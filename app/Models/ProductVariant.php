<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'barcode',
        'price',
        'cost',
        'tax_id',
        'unit_id',
        'pack_size',
        'low_stock_threshold',
        'track_inventory',
        'is_loose',
        'is_active',
        'can_edit_price',
        'media_id',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'pack_size' => 'decimal:3',
            'low_stock_threshold' => 'decimal:3',
            'track_inventory' => 'boolean',
            'is_loose' => 'boolean',
            'is_active' => 'boolean',
            'can_edit_price' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function tax(): BelongsTo
    {
        // Falls back to the parent product's tax if this variant has none set.
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    public function documentLines(): HasMany
    {
        return $this->hasMany(DocumentLine::class, 'variant_id');
    }

    /**
     * The effective tax to apply — variant override, else parent product's default.
     */
    public function effectiveTax(): ?Tax
    {
        return $this->tax ?? $this->product?->tax;
    }

    /**
     * Current stock on hand, computed live from the movement ledger.
     * Pass $locationId to scope to a single location, or omit for the
     * total across all locations.
     * For hot paths (POS grid), prefer a StockLevel cache table instead — see note below.
     */
    public function stockOnHand(?int $locationId = null): float
    {
        $query = $this->stockMovements();

        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        return (float) $query->sum('quantity_change');
    }

    /**
     * Whether this variant is at or below its configured low-stock threshold.
     * Returns false if inventory isn't tracked for this variant, or no
     * threshold has been configured (null means "no alert wanted").
     */
    public function isLowStock(?int $locationId = null): bool
    {
        if (! $this->track_inventory || $this->low_stock_threshold === null) {
            return false;
        }

        return $this->stockOnHand($locationId) <= (float) $this->low_stock_threshold;
    }

    /**
     * A display label combining product name + variant name, e.g. "Coca-Cola — 500ml"
     */
    public function displayName(): string
    {
        return trim(($this->product?->name ?? '') . ' — ' . $this->name, ' —');
    }
}