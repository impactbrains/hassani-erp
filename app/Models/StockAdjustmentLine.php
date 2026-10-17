<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustmentLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:6',
        'expiry_date' => 'date:Y-m-d',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }
}
