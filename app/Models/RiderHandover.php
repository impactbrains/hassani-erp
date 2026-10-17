<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderHandover extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'variance' => 'decimal:2',
        'received_at' => 'datetime',
        'business_date' => 'date:Y-m-d',
    ];

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'rider_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
