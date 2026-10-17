<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VariantUnit extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'factor' => 'decimal:6',
        'is_base' => 'boolean',
        'can_sell' => 'boolean',
        'can_purchase' => 'boolean',
        'is_default_sale' => 'boolean',
        'is_default_purchase' => 'boolean',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class, 'variant_unit_id');
    }
}
