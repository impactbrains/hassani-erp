<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'gross_total' => 'decimal:2',
        'deduction_total' => 'decimal:2',
        'net_total' => 'decimal:2',
        'employer_cost_total' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'working_days' => 'integer',
    ];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
