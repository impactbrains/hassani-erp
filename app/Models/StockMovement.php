<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the immutable stock ledger. Quantities are signed and in base units.
 */
class StockMovement extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:2',
        'occurred_at' => 'datetime',
        'business_date' => 'date:Y-m-d',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }


    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException(static::class.' rows are append-only; post a reversal.'));
        static::deleting(fn () => throw new \LogicException(static::class.' rows are append-only; post a reversal.'));
    }
}
