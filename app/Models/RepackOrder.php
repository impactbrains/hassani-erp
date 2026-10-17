<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepackOrder extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'source_quantity' => 'decimal:3',
        'target_quantity' => 'decimal:3',
        'packaging_quantity' => 'decimal:3',
        'posted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sourceVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'source_variant_id');
    }

    public function targetVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'target_variant_id');
    }

    public function packagingVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'packaging_variant_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function targetLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'target_location_id');
    }
}
