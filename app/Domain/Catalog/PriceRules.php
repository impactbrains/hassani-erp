<?php

namespace App\Domain\Catalog;

use App\Models\PriceRule;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\VariantUnit;
use App\Support\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;

/**
 * Applies price rules to a cart (POS, sales orders, and a single item on scan or search).
 *
 *  - The starting price of each line is its list price: the customer's own price list, else In-store.
 *  - Rules are checked in order: rules for that product first, then category and brand rules,
 *    then rules for every product; inside each group, in the order they were arranged.
 *  - The first rule whose conditions are true AND that gives a lower price sets the line's price.
 *    Rules never add up, and never raise a price.
 *  - A customer who has their own price list (for example Wholesale) already gets that list's
 *    price. Rules apply to them only when the rule names them, their customer type or their
 *    price list, so a wholesale customer never gets wholesale twice.
 *  - Quantity is the whole cart's quantity of that SKU, counted in its base unit (or in the pack the
 *    rule names). Once a rule applies, every unit gets the rule's price.
 *  - "Wholesale price" with no wholesale price set for that pack gives nothing, so the line keeps
 *    its In-store price.
 * Rule prices are list prices: no manager approval is needed for them. The MRP cap and margin floor
 * checks in checkout are unchanged.
 */
final class PriceRules
{
    /** @var array<int, Collection<int, PriceRule>> */
    private array $rules = [];

    /** @var array<int, array<int, ?int>> category id => parent id */
    private array $parents = [];

    public function __construct(private readonly PriceResolver $prices)
    {
    }

    /**
     * @param  array<int|string, array{variant: ProductVariant, unit: VariantUnit, quantity: string, base_quantity: string, baseline: string|null}>  $items
     * @return array<int|string, array{price: string|null, baseline: string|null, rule_id: int|null, tag: array<string, mixed>|null, hint: array<string, mixed>|null}>
     */
    public function apply(array $items, PriceContext $context): array
    {
        $out = [];
        foreach ($items as $key => $item) {
            $out[$key] = ['price' => $item['baseline'], 'baseline' => $item['baseline'], 'rule_id' => null, 'tag' => null, 'hint' => null];
        }
        $rules = $this->activeRules();
        if ($rules->isEmpty() || $items === []) {
            return $out;
        }

        $quantities = [];
        $billTotal = '0';
        foreach ($items as $item) {
            $quantities[$item['variant']->id] = Decimal::add($quantities[$item['variant']->id] ?? '0', $item['base_quantity']);
            if ($item['baseline'] !== null) {
                $billTotal = Decimal::add($billTotal, Decimal::mul($item['baseline'], $item['quantity']));
            }
        }
        $cart = ['quantities' => $quantities, 'bill_total' => Decimal::money($billTotal)];
        $ownList = $this->customerHasOwnList($context);

        foreach ($items as $key => $item) {
            if ($item['baseline'] === null) {
                continue; // no price at all: the sale needs a price set (or an approved manual price), not a rule
            }
            $item['variant']->loadMissing('product', 'units.unit');
            $hint = null;
            foreach ($this->rulesFor($item['variant'], $rules) as $rule) {
                if ($ownList && ! $rule->targetsCustomers()) {
                    continue;
                }
                $met = $this->conditionsMet($rule, $item, $context, $cart);
                if (! $met && $hint !== null) {
                    continue; // only the first near-miss becomes a hint
                }
                $price = $this->rulePrice($rule, $item);
                if ($price === null || ! Decimal::lt($price, $item['baseline'])) {
                    continue; // a rule only ever lowers a price
                }
                if ($met) {
                    $out[$key]['price'] = $price;
                    $out[$key]['rule_id'] = $rule->id;
                    $out[$key]['tag'] = $this->tag($rule);
                    break;
                }
                $hint = $this->hint($rule, $item, $context, $cart);
            }
            $out[$key]['hint'] = $out[$key]['rule_id'] === null ? $hint : null;
        }

        return $out;
    }

    /**
     * The price of one pack on its own (quantity 1), for scan and search results.
     *
     * @return array{price: string|null, rule_id: int|null, tag: array<string, mixed>|null}
     */
    public function single(ProductVariant $variant, VariantUnit $unit, ?string $baseline, PriceContext $context): array
    {
        $result = $this->apply([0 => ['variant' => $variant, 'unit' => $unit, 'quantity' => '1', 'base_quantity' => (string) $unit->factor, 'baseline' => $baseline]], $context)[0];

        return ['price' => $result['price'], 'rule_id' => $result['rule_id'], 'tag' => $result['tag']];
    }

    /** What a rule would charge for this line's pack, or null when it has nothing to offer. */
    public function rulePrice(PriceRule $rule, array $item): ?string
    {
        /** @var ProductVariant $variant */
        $variant = $item['variant'];
        /** @var VariantUnit $unit */
        $unit = $item['unit'];

        return match ($rule->action) {
            'wholesale' => $this->prices->bookPrice($variant, $unit, $this->prices->wholesaleBookId()),
            'percent_off' => ($inStore = $this->prices->bookPrice($variant, $unit, $this->prices->defaultBookId())) === null ? null
                : Decimal::money(Decimal::sub($inStore, Decimal::percentOf($inStore, (string) $rule->value))),
            'fixed' => $this->fixedPrice($rule, $variant, $unit),
            default => null,
        };
    }

    /** A fixed price is typed for one pack; another pack of the same SKU gets the same price per base unit. */
    private function fixedPrice(PriceRule $rule, ProductVariant $variant, VariantUnit $unit): ?string
    {
        if ($rule->value === null) {
            return null;
        }
        $ruleUnit = $rule->unit_id === null
            ? $variant->units->firstWhere('is_base', true)
            : $variant->units->firstWhere('unit_id', (int) $rule->unit_id);
        if ($ruleUnit === null) {
            return null;
        }
        if ((int) $ruleUnit->id === (int) $unit->id) {
            return Decimal::money((string) $rule->value);
        }

        return Decimal::money(Decimal::mul(Decimal::div((string) $rule->value, $ruleUnit->factor, 8), $unit->factor));
    }

    /** @param array{quantities: array<int, string>, bill_total: string} $cart */
    private function conditionsMet(PriceRule $rule, array $item, PriceContext $context, array $cart): bool
    {
        $conditions = $rule->conditions ?? [];
        if ($conditions === []) {
            return true;
        }
        $results = array_map(fn (array $c) => $this->conditionMet($c, $item, $context, $cart), $conditions);

        return $rule->match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array{quantities: array<int, string>, bill_total: string}  $cart
     */
    private function conditionMet(array $c, array $item, PriceContext $context, array $cart): bool
    {
        $customer = $context->customer;

        return match ($c['type'] ?? null) {
            'quantity' => ($qty = $this->quantityIn($c, $item, $cart)) !== null && $this->compare($qty, $c['op'] ?? 'gte', (string) ($c['value'] ?? '0')),
            'line_value' => $this->compare(Decimal::mul($item['baseline'], $item['quantity']), $c['op'] ?? 'gte', (string) ($c['value'] ?? '0')),
            'bill_total' => $this->compare($cart['bill_total'], $c['op'] ?? 'gte', (string) ($c['value'] ?? '0')),
            'customer_type' => in_array($customer === null ? 'walk_in' : ($customer->customerProfile?->category ?: 'retail'), (array) ($c['values'] ?? []), true),
            'customer' => $customer !== null && in_array($customer->id, array_map('intval', (array) ($c['ids'] ?? [])), true),
            'price_list' => in_array($customer?->customerProfile?->price_book_id ?: $this->prices->defaultBookId(), array_map('intval', (array) ($c['ids'] ?? [])), true),
            'store' => in_array($context->storeId, array_map('intval', (array) ($c['ids'] ?? [])), true),
            'pack' => in_array((int) $item['unit']->unit_id, array_map('intval', (array) ($c['unit_ids'] ?? [])), true),
            'date' => (empty($c['from']) || $context->date >= $c['from']) && (empty($c['to']) || $context->date <= $c['to']),
            default => false,
        };
    }

    /** The cart's quantity of this SKU, in the condition's pack (base unit when none). Null when the SKU has no such pack. */
    private function quantityIn(array $c, array $item, array $cart): ?string
    {
        $base = $cart['quantities'][$item['variant']->id] ?? $item['base_quantity'];
        if (empty($c['unit_id'])) {
            return $base;
        }
        $pack = $item['variant']->units->firstWhere('unit_id', (int) $c['unit_id']);

        return $pack === null ? null : Decimal::div($base, $pack->factor, 6);
    }

    private function compare(string $actual, string $op, string $value): bool
    {
        return match ($op) {
            'gt' => Decimal::gt($actual, $value),
            'lte' => Decimal::lte($actual, $value),
            'lt' => Decimal::lt($actual, $value),
            default => Decimal::gte($actual, $value),
        };
    }

    /**
     * "Add 2 more for the wholesale price": only when a "quantity at least / more than" condition is the one
     * thing missing, and the cart already has at least half of that quantity.
     *
     * @return array<string, mixed>|null
     */
    private function hint(PriceRule $rule, array $item, PriceContext $context, array $cart): ?array
    {
        $conditions = $rule->conditions ?? [];
        if ($rule->match === 'any' && count($conditions) > 1) {
            return null;
        }
        $quantity = null;
        foreach ($conditions as $c) {
            $met = $this->conditionMet($c, $item, $context, $cart);
            if ($met) {
                continue;
            }
            if (($c['type'] ?? null) !== 'quantity' || ! in_array($c['op'] ?? 'gte', ['gte', 'gt'], true) || $quantity !== null) {
                return null;
            }
            $quantity = $c;
        }
        if ($quantity === null) {
            return null;
        }
        $have = $this->quantityIn($quantity, $item, $cart);
        $target = (string) $quantity['value'];
        if ($have === null || Decimal::lt(Decimal::mul($have, '2'), $target)) {
            return null; // not close yet
        }
        $unit = empty($quantity['unit_id']) ? $item['variant']->units->firstWhere('is_base', true) : $item['variant']->units->firstWhere('unit_id', (int) $quantity['unit_id']);
        $wholeUnits = ! ($unit?->unit?->allows_fraction ?? false);
        $more = null;
        if ($wholeUnits) {
            $needed = $quantity['op'] === 'gt' ? (string) ((int) floor((float) $target) + 1) : (string) (int) ceil((float) $target);
            $more = Decimal::trim(Decimal::sub($needed, $have));
            if (! Decimal::isPositive($more)) {
                return null;
            }
        }

        return $this->tag($rule) + ['more' => $more, 'unit' => $unit?->unit?->code, 'target' => Decimal::trim($target), 'op' => $quantity['op']];
    }

    /**
     * What the cart line shows, e.g. "Wholesale price · 6+". Sent as data so the screen can translate it.
     *
     * @return array<string, mixed>
     */
    public function tag(PriceRule $rule): array
    {
        $quantity = collect($rule->conditions ?? [])->first(fn ($c) => ($c['type'] ?? null) === 'quantity' && in_array($c['op'] ?? 'gte', ['gte', 'gt'], true));

        return [
            'action' => $rule->action,
            'percent' => $rule->action === 'percent_off' ? Decimal::trim((string) $rule->value) : null,
            'name' => $rule->name,
            'from' => $quantity && $rule->match === 'all' ? ['op' => $quantity['op'] ?? 'gte', 'value' => Decimal::trim((string) $quantity['value']), 'unit' => ! empty($quantity['unit_id']) ? \App\Models\Unit::query()->whereKey($quantity['unit_id'])->value('code') : null] : null,
        ];
    }

    /**
     * Rules that can apply to this SKU, most specific first.
     *
     * @param  Collection<int, PriceRule>  $rules
     * @return Collection<int, PriceRule>
     */
    private function rulesFor(ProductVariant $variant, Collection $rules): Collection
    {
        $categories = $this->categoryChain($variant->product?->category_id);
        $brandId = $variant->product?->brand_id;

        return $rules->filter(fn (PriceRule $r) => match ($r->scope) {
            'variant' => (int) $r->variant_id === (int) $variant->id,
            'category' => in_array((int) $r->category_id, $categories, true),
            'brand' => $brandId !== null && (int) $r->brand_id === (int) $brandId,
            'all' => true,
            default => false,
        })->values();
    }

    /** @return list<int> the category and every category above it */
    private function categoryChain(?int $categoryId): array
    {
        $company = CurrentCompany::require();
        $this->parents[$company] ??= ProductCategory::query()->pluck('parent_id', 'id')->map(fn ($p) => $p === null ? null : (int) $p)->all();
        $chain = [];
        while ($categoryId !== null && ! in_array($categoryId, $chain, true)) {
            $chain[] = $categoryId;
            $categoryId = $this->parents[$company][$categoryId] ?? null;
        }

        return $chain;
    }

    /** @return Collection<int, PriceRule> */
    private function activeRules(): Collection
    {
        $company = CurrentCompany::require();

        return $this->rules[$company] ??= PriceRule::query()->where('is_active', true)->get()
            ->sortBy(fn (PriceRule $r) => [$r->rank(), $r->sort_order, $r->id])->values();
    }

    private function customerHasOwnList(PriceContext $context): bool
    {
        $book = $context->customer?->customerProfile?->price_book_id;

        return $book !== null && (int) $book !== (int) $this->prices->defaultBookId();
    }

    /** Forget cached rules (after rules are saved in the same request). */
    public function flush(): void
    {
        $this->rules = [];
        $this->parents = [];
    }
}
