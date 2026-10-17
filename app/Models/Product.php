<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product as customers know it ("Tapal Family Mixture"). Sellable SKUs are its variants.
 */
class Product extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'track_batches' => 'boolean',
        'track_expiry' => 'boolean',
        'is_online' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }


    public function isStocked(): bool
    {
        return in_array($this->type, ['stocked', 'bundle'], true);
    }
}
