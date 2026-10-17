<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariantCost extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'average_cost' => 'decimal:6',
        'last_purchase_cost' => 'decimal:6',
        'updated_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
