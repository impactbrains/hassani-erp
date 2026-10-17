<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceRepayment extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'repaid_on' => 'date:Y-m-d',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
