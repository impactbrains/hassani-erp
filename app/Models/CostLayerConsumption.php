<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostLayerConsumption extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:6',
    ];

    public function layer(): BelongsTo
    {
        return $this->belongsTo(CostLayer::class, 'cost_layer_id');
    }
}
