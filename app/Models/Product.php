<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category_id',
        'brand',
        'description',
        'tax_id',
        'has_variants',
        'is_active',
        'can_edit_price',
        'media_id',
    ];

    protected function casts(): array
    {
        return [
            'has_variants' => 'boolean',
            'is_active' => 'boolean',
            'can_edit_price' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}