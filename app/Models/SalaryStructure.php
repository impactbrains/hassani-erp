<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructure extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'base_amount' => 'decimal:2',
        'overtime_hourly_rate' => 'decimal:2',
        'components' => 'array',
        'eobi_employee' => 'decimal:2',
        'eobi_employer' => 'decimal:2',
        'social_security_employer' => 'decimal:2',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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
