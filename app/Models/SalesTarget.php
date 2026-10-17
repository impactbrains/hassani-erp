<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'target_value' => 'decimal:2',
    ];

    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sales_agent_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
