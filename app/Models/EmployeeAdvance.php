<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAdvance extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'recovery_start_month' => 'date:Y-m-d',
        'recovered_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'business_date' => 'date:Y-m-d',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(AdvanceRepayment::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }


    public function outstanding(): string
    {
        return Decimal::money(Decimal::sub($this->amount, $this->recovered_amount));
    }
}
