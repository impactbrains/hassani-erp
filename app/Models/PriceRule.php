<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "When these conditions are true, charge this price." For one SKU (scope variant), a category,
 * a brand or every product (scope all). See App\Domain\Catalog\PriceRules for how rules are applied.
 *
 * conditions: list of
 *   {type: quantity|line_value|bill_total, op: gte|gt|lte|lt, value: string, unit_id?: int|null}
 *   {type: customer_type, values: list<retail|wholesale|shopkeeper|institution|walk_in>}
 *   {type: customer|price_list|store, ids: list<int>}
 *   {type: pack, unit_ids: list<int>}
 *   {type: date, from?: Y-m-d|null, to?: Y-m-d|null}
 * action: wholesale (the Wholesale price list), fixed (value per unit_id pack), percent_off (value % off the In-store price).
 */
class PriceRule extends Model
{
    use BelongsToCompany;

    public const SCOPES = ['variant', 'category', 'brand', 'all'];

    public const ACTIONS = ['wholesale', 'fixed', 'percent_off'];

    /** Condition types that pick out customers. Only rules with one of these apply to a customer who has their own price list. */
    public const CUSTOMER_CONDITIONS = ['customer', 'customer_type', 'price_list'];

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'conditions' => 'array',
        'value' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function targetsCustomers(): bool
    {
        return collect($this->conditions ?? [])->contains(fn ($c) => in_array($c['type'] ?? null, self::CUSTOMER_CONDITIONS, true));
    }

    /** Rules for one product come first, then category and brand rules, then rules for every product. */
    public function rank(): int
    {
        return match ($this->scope) {
            'variant' => 0,
            'category', 'brand' => 1,
            default => 2,
        };
    }
}
