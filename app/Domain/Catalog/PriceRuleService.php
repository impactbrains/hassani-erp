<?php

namespace App\Domain\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\PriceRule;
use App\Models\ProductVariant;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Saves price rules after checking them, so PriceRules only ever sees clean data.
 * Rules for one product live on the product form; shared rules (a category, a brand or every
 * product) live on the Pricing rules screen. A fixed price only makes sense for one product,
 * so shared rules can use the wholesale price or a percentage off.
 */
final class PriceRuleService
{
    public const CUSTOMER_TYPES = ['walk_in', 'retail', 'wholesale', 'shopkeeper', 'institution'];

    private const OPS = ['gte', 'gt', 'lte', 'lt'];

    /**
     * Replaces the rules of one SKU with the given list (rules with an id are updated, others added,
     * missing ones removed). The list order is the order they are checked in.
     *
     * @param  list<array<string, mixed>>  $rules
     */
    public function syncVariant(ProductVariant $variant, array $rules): void
    {
        $keep = [];
        foreach (array_values($rules) as $order => $data) {
            $rule = isset($data['id']) ? PriceRule::query()->where('variant_id', $variant->id)->find($data['id']) : null;
            $keep[] = $this->save(['scope' => 'variant', 'variant_id' => $variant->id, 'sort_order' => $order] + $data, $rule, $variant)->id;
        }
        PriceRule::query()->where('variant_id', $variant->id)->whereNotIn('id', $keep)->get()->each(function (PriceRule $old) use ($variant) {
            $old->delete();
            Audit::record('price_rule.deleted', "Removed a price rule from {$variant->sku}", null, ['rule' => $old->only(['conditions', 'action', 'value'])]);
        });
        app(PriceRules::class)->flush();
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?PriceRule $rule = null, ?ProductVariant $variant = null): PriceRule
    {
        $clean = $this->clean($data, $variant);
        $isNew = $rule === null;
        $rule ??= new PriceRule(['created_by' => Auth::id()]);
        $before = $isNew ? [] : $rule->only(['name', 'match', 'conditions', 'action', 'value', 'unit_id', 'is_active']);
        $rule->fill($clean + ['updated_by' => Auth::id()]);
        $rule->save();
        if ($isNew || $before !== $rule->only(array_keys($before))) {
            Audit::record($isNew ? 'price_rule.created' : 'price_rule.updated', ($isNew ? 'Added' : 'Changed').' price rule: '.$this->describe($rule), $rule, $before, $rule->only(['name', 'match', 'conditions', 'action', 'value', 'unit_id', 'is_active']));
        }
        app(PriceRules::class)->flush();

        return $rule;
    }

    /**
     * Copies a rule onto other SKUs. A fixed price is copied only to SKUs that have the pack it is priced in.
     *
     * @param  list<int>  $variantIds
     * @return array{copied: int, skipped: list<string>}
     */
    public function copyToVariants(PriceRule $rule, array $variantIds): array
    {
        $copied = 0;
        $skipped = [];
        DB::transaction(function () use ($rule, $variantIds, &$copied, &$skipped) {
            foreach (ProductVariant::query()->whereIn('id', $variantIds)->with('units')->get() as $variant) {
                if ((int) $variant->id === (int) $rule->variant_id) {
                    continue;
                }
                if ($rule->action === 'fixed' && $rule->unit_id !== null && ! $variant->units->contains('unit_id', (int) $rule->unit_id)) {
                    $skipped[] = $variant->displayName();

                    continue;
                }
                $next = (int) PriceRule::query()->where('variant_id', $variant->id)->max('sort_order') + 1;
                $this->save(['scope' => 'variant', 'variant_id' => $variant->id, 'sort_order' => $next] + $rule->only(['name', 'match', 'conditions', 'action', 'value', 'unit_id', 'is_active']), null, $variant);
                $copied++;
            }
        });

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /** @param list<int> $ids shared rules in their new order */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $order => $id) {
            PriceRule::query()->where('scope', '!=', 'variant')->whereKey($id)->update(['sort_order' => $order]);
        }
        Audit::record('price_rule.reordered', 'Changed the order of shared price rules');
        app(PriceRules::class)->flush();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data, ?ProductVariant $variant): array
    {
        $scope = $data['scope'] ?? null;
        if (! in_array($scope, PriceRule::SCOPES, true)) {
            throw new BusinessRuleException('Choose what the rule applies to.', 'invalid_rule', field: 'scope');
        }
        $target = match ($scope) {
            'variant' => ['variant_id' => (int) ($data['variant_id'] ?? 0) ?: throw new BusinessRuleException('Choose the product.', 'invalid_rule', field: 'variant_id')],
            'category' => ['category_id' => (int) ($data['category_id'] ?? 0) ?: throw new BusinessRuleException('Choose the category.', 'invalid_rule', field: 'category_id')],
            'brand' => ['brand_id' => (int) ($data['brand_id'] ?? 0) ?: throw new BusinessRuleException('Choose the brand.', 'invalid_rule', field: 'brand_id')],
            default => [],
        };
        $action = $data['action'] ?? 'wholesale';
        if (! in_array($action, PriceRule::ACTIONS, true)) {
            throw new BusinessRuleException('Choose what price the rule gives.', 'invalid_rule', field: 'action');
        }
        if ($action === 'fixed' && $scope !== 'variant') {
            throw new BusinessRuleException('A fixed price can only be set on one product. For a category, brand or all products, use the wholesale price or a percentage off.', 'invalid_rule', field: 'action');
        }
        $value = null;
        $unitId = null;
        if ($action === 'fixed') {
            $value = $this->number($data['value'] ?? null, 'Type the fixed price.');
            if (! Decimal::isPositive($value)) {
                throw new BusinessRuleException('The fixed price must be more than 0.', 'invalid_rule', field: 'value');
            }
            $unitId = ! empty($data['unit_id']) ? (int) $data['unit_id'] : null;
            if ($unitId !== null && $variant !== null && ! $variant->units()->where('unit_id', $unitId)->exists()) {
                throw new BusinessRuleException('The fixed price is for a pack this product does not have.', 'invalid_rule', field: 'unit_id');
            }
        }
        if ($action === 'percent_off') {
            $value = $this->number($data['value'] ?? null, 'Type how many percent off.');
            if (! Decimal::isPositive($value) || ! Decimal::lt($value, '100')) {
                throw new BusinessRuleException('The percentage off must be more than 0 and less than 100.', 'invalid_rule', field: 'value');
            }
        }

        return $target + [
            'scope' => $scope,
            'name' => isset($data['name']) && trim((string) $data['name']) !== '' ? mb_substr(trim((string) $data['name']), 0, 120) : null,
            'match' => ($data['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'conditions' => array_map(fn ($c) => $this->condition($c), array_values((array) ($data['conditions'] ?? []))),
            'action' => $action,
            'value' => $value,
            'unit_id' => $unitId,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param  mixed  $c
     * @return array<string, mixed>
     */
    private function condition($c): array
    {
        if (! is_array($c)) {
            throw new BusinessRuleException('A condition is not filled in.', 'invalid_rule', field: 'conditions');
        }
        $type = $c['type'] ?? null;
        $ids = fn (string $key, string $message) => ($list = array_values(array_unique(array_filter(array_map('intval', (array) ($c[$key] ?? [])))))) !== [] ? $list : throw new BusinessRuleException($message, 'invalid_rule', field: 'conditions');

        return match ($type) {
            'quantity', 'line_value', 'bill_total' => array_filter([
                'type' => $type,
                'op' => in_array($c['op'] ?? 'gte', self::OPS, true) ? ($c['op'] ?? 'gte') : 'gte',
                'value' => ($v = $this->number($c['value'] ?? null, $type === 'quantity' ? 'Type the quantity in the condition.' : 'Type the amount in the condition.')) !== null && ! Decimal::isNegative($v) ? Decimal::trim($v) : throw new BusinessRuleException('A condition cannot use a negative number.', 'invalid_rule', field: 'conditions'),
                'unit_id' => $type === 'quantity' && ! empty($c['unit_id']) ? (int) $c['unit_id'] : null,
            ], fn ($v) => $v !== null),
            'customer_type' => ['type' => $type, 'values' => ($values = array_values(array_intersect(self::CUSTOMER_TYPES, (array) ($c['values'] ?? [])))) !== [] ? $values : throw new BusinessRuleException('Choose at least one customer type.', 'invalid_rule', field: 'conditions')],
            'customer' => ['type' => $type, 'ids' => $ids('ids', 'Choose at least one customer.')],
            'price_list' => ['type' => $type, 'ids' => $ids('ids', 'Choose at least one price list.')],
            'store' => ['type' => $type, 'ids' => $ids('ids', 'Choose at least one store.')],
            'pack' => ['type' => $type, 'unit_ids' => $ids('unit_ids', 'Choose at least one pack size.')],
            'date' => $this->dates($c),
            default => throw new BusinessRuleException('Unknown condition.', 'invalid_rule', field: 'conditions'),
        };
    }

    /** @return array<string, string> */
    private function dates(array $c): array
    {
        $from = $this->date($c['from'] ?? null);
        $to = $this->date($c['to'] ?? null);
        if ($from === null && $to === null) {
            throw new BusinessRuleException('Give a start date, an end date, or both.', 'invalid_rule', field: 'conditions');
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new BusinessRuleException('The end date is before the start date.', 'invalid_rule', field: 'conditions');
        }

        return array_filter(['type' => 'date', 'from' => $from, 'to' => $to]);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);

        return $date === false ? throw new BusinessRuleException('A date in a condition is not valid.', 'invalid_rule', field: 'conditions') : $date->format('Y-m-d');
    }

    private function number(mixed $value, string $message): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new BusinessRuleException($message, 'invalid_rule', field: 'conditions');
        }

        return (string) $value;
    }

    /** A short English summary for the audit trail, e.g. "quantity at least 6 -> wholesale price". */
    public function describe(PriceRule $rule): string
    {
        $words = ['gte' => 'at least', 'gt' => 'more than', 'lte' => 'at most', 'lt' => 'less than'];
        $parts = array_map(fn ($c) => match ($c['type']) {
            'quantity' => 'quantity '.$words[$c['op']].' '.$c['value'],
            'line_value' => 'line value '.$words[$c['op']].' Rs '.$c['value'],
            'bill_total' => 'bill total '.$words[$c['op']].' Rs '.$c['value'],
            'date' => 'dates '.($c['from'] ?? '…').' to '.($c['to'] ?? '…'),
            default => str_replace('_', ' ', $c['type']),
        }, $rule->conditions ?? []);
        $when = $parts === [] ? 'always' : implode($rule->match === 'any' ? ' or ' : ' and ', $parts);
        $then = match ($rule->action) {
            'wholesale' => 'wholesale price',
            'fixed' => 'Rs '.Decimal::trim((string) $rule->value),
            default => Decimal::trim((string) $rule->value).'% off the In-store price',
        };

        return "{$when} -> {$then}";
    }
}
