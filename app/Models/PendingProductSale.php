<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingProductSale extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['sold_at' => 'datetime'];

    public function pendingProduct(): BelongsTo
    {
        return $this->belongsTo(PendingProduct::class);
    }
}
