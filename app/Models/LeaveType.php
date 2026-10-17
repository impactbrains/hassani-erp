<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'is_paid' => 'boolean',
        'default_days_per_year' => 'decimal:1',
        'carry_forward_max' => 'decimal:1',
        'requires_document' => 'boolean',
        'is_active' => 'boolean',
    ];
}
