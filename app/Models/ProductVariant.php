<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable, stock-keeping unit (SKU). Stock is held in its base unit.
 */
class ProductVariant extends Model
{
    protected $appends = ['display_name'];

    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'mrp' => 'decimal:2',
        'attributes' => 'array',
        'is_loose' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(VariantUnit::class, 'variant_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class, 'variant_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'variant_id');
    }

    public function bundleComponents(): HasMany
    {
        return $this->hasMany(BundleComponent::class, 'bundle_variant_id');
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'variant_id');
    }


    /** "Product – Variant" when the product is loaded with the variant; plain variant name otherwise (never lazy-loads). */
    public function getDisplayNameAttribute(): string
    {
        $name = (string) ($this->attributes['name'] ?? '');
        if (! $this->relationLoaded('product') || $this->product === null || $this->product->name === $name || $name === '') {
            return $name !== '' ? $name : (string) $this->product?->name;
        }

        return $this->product->name.' – '.$name;
    }

    public function displayName(): string
    {
        $product = $this->product;
        if ($product === null || $this->name === $product->name) {
            return $this->name;
        }

        return $product->name.' – '.$this->name;
    }

    public function displayNameUr(): ?string
    {
        $productUr = $this->product?->name_ur;
        if ($this->name_ur && $productUr && $this->name_ur !== $productUr) {
            return $productUr.' – '.$this->name_ur;
        }

        return $this->name_ur ?: $productUr;
    }

    public function isBundle(): bool
    {
        return $this->product?->type === 'bundle';
    }
}
