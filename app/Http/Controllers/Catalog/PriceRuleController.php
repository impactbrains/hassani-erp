<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\PriceRuleService;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\PriceBook;
use App\Models\PriceRule;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Unit;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pricing rules screen: shared rules (a category, a brand or every product) in the order they are
 * checked, and a list of every product's own rules with "Copy to other products".
 * Rules for one product are edited on the product form.
 */
class PriceRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'catalog.products.view');
        $tab = $request->query('tab') === 'products' ? 'products' : 'shared';

        return Inertia::render('catalog/PriceRules', [
            'tab' => $tab,
            'shared' => PriceRule::query()->where('scope', '!=', 'variant')->with('category:id,name,name_ur', 'brand:id,name')->orderBy('sort_order')->orderBy('id')->get(),
            'productRules' => PriceRule::query()->where('scope', 'variant')
                ->with(['variant:id,product_id,sku,name,base_unit_id', 'variant.product:id,name,name_ur', 'variant.baseUnit:id,code', 'unit:id,code'])
                ->orderBy('variant_id')->orderBy('sort_order')->orderBy('id')->limit(1000)->get(),
            'canManage' => $request->user()->hasPermission('catalog.prices.manage'),
        ] + $this->builderData());
    }

    public function store(Request $request, PriceRuleService $rules): RedirectResponse
    {
        $this->allow($request, 'catalog.prices.manage');
        $data = $this->validated($request);
        $data['sort_order'] = (int) PriceRule::query()->where('scope', '!=', 'variant')->max('sort_order') + 1;
        $rules->save($data);

        return back()->with('success', 'Rule added.');
    }

    public function update(Request $request, PriceRule $rule, PriceRuleService $rules): RedirectResponse
    {
        $this->allow($request, 'catalog.prices.manage');
        abort_if($rule->scope === 'variant', 422, 'Change this rule on the product form.');
        $rules->save($this->validated($request) + ['sort_order' => $rule->sort_order], $rule);

        return back()->with('success', 'Rule saved.');
    }

    public function destroy(Request $request, PriceRule $rule, PriceRuleService $rules): RedirectResponse
    {
        $this->allow($request, 'catalog.prices.manage');
        $summary = $rules->describe($rule);
        $rule->delete();
        Audit::record('price_rule.deleted', "Removed price rule: {$summary}");
        app(\App\Domain\Catalog\PriceRules::class)->flush();

        return back()->with('success', 'Rule removed.');
    }

    public function reorder(Request $request, PriceRuleService $rules): RedirectResponse
    {
        $this->allow($request, 'catalog.prices.manage');
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);
        $rules->reorder($data['ids']);

        return back()->with('success', 'Order saved.');
    }

    /** Copies one product's rule to other products. */
    public function copy(Request $request, PriceRule $rule, PriceRuleService $rules): RedirectResponse
    {
        $this->allow($request, 'catalog.prices.manage');
        abort_unless($rule->scope === 'variant', 422, 'Only a product’s own rule can be copied to other products.');
        $data = $request->validate(['variant_ids' => ['required', 'array', 'min:1', 'max:200'], 'variant_ids.*' => ['integer']]);
        $result = $rules->copyToVariants($rule, $data['variant_ids']);
        $message = $result['copied'] === 1 ? 'Copied to 1 product.' : "Copied to {$result['copied']} products.";
        if ($result['skipped'] !== []) {
            $message .= ' Not copied to '.implode(', ', array_slice($result['skipped'], 0, 5)).(count($result['skipped']) > 5 ? ' …' : '').' because they do not have the pack the fixed price is for.';
        }

        return back()->with('success', $message);
    }

    /**
     * Lists the rule builder needs (also used by the product form).
     *
     * @return array<string, mixed>
     */
    public function builderData(): array
    {
        $customerIds = PriceRule::query()->get(['conditions'])->flatMap(fn ($r) => collect($r->conditions ?? [])->where('type', 'customer')->flatMap(fn ($c) => $c['ids'] ?? []))->unique()->values()->all();

        return [
            'ruleOptions' => [
                'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'name_ur', 'allows_fraction']),
                'stores' => Store::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_ur']),
                'priceBooks' => PriceBook::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'name_ur', 'is_default']),
                'categories' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_ur']),
                'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_ur']),
                'customers' => $customerIds === [] ? [] : Contact::query()->whereIn('id', $customerIds)->get(['id', 'code', 'name', 'name_ur', 'phone']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'scope' => ['required', 'in:category,brand,all'],
            'category_id' => ['nullable', 'required_if:scope,category', 'integer'],
            'brand_id' => ['nullable', 'required_if:scope,brand', 'integer'],
            'match' => ['nullable', 'in:all,any'],
            'conditions' => ['array', 'max:10'],
            'action' => ['required', 'in:wholesale,percent_off'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ], ['category_id.required_if' => 'Choose the category.', 'brand_id.required_if' => 'Choose the brand.']);
    }
}
