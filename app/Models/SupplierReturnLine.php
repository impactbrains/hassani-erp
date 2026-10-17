<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturnLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'base_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:6',
        'line_total' => 'decimal:2',
    ];

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class, 'supplier_return_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }
}
