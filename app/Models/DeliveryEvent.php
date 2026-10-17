<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
