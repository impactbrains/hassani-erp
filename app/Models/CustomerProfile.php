<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'marketing_consent' => 'boolean',
        'consent_channels' => 'array',
        'consent_recorded_at' => 'datetime',
        'payment_terms_days' => 'integer',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sales_agent_id');
    }
}
