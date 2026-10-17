<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_factor' => 'decimal:6',
        'base_quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'line_total' => 'decimal:2',
        'base_quantity_received' => 'decimal:3',
        'base_quantity_cancelled' => 'decimal:3',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function variantUnit(): BelongsTo
    {
        return $this->belongsTo(VariantUnit::class);
    }
}
