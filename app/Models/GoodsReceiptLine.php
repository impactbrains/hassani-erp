<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'unit_factor' => 'decimal:6',
        'quantity_expected' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'quantity_damaged' => 'decimal:3',
        'base_quantity_accepted' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'landed_cost_share' => 'decimal:2',
        'base_unit_cost' => 'decimal:6',
        'expiry_date' => 'date:Y-m-d',
        'base_quantity_billed' => 'decimal:3',
        'base_quantity_returned' => 'decimal:3',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function variantUnit(): BelongsTo
    {
        return $this->belongsTo(VariantUnit::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
