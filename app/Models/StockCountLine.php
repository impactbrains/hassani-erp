<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'expected_quantity' => 'decimal:3',
        'counted_quantity' => 'decimal:3',
        'second_count_quantity' => 'decimal:3',
        'movement_since_snapshot' => 'decimal:3',
        'variance_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:6',
        'variance_value' => 'decimal:2',
        'counted_at' => 'datetime',
    ];

    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
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

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function secondCounter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_counted_by');
    }

    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }
}
