<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'days_payable' => 'decimal:1',
        'days_present' => 'decimal:1',
        'days_absent' => 'decimal:1',
        'days_paid_leave' => 'decimal:1',
        'days_unpaid_leave' => 'decimal:1',
        'gross_pay' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'employer_contributions' => 'decimal:2',
        'paid_at' => 'datetime',
        'late_count' => 'integer',
        'overtime_minutes' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
