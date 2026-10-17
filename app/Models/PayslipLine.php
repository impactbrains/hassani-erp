<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'rate' => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
