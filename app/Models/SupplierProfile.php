<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProfile extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['bank_details'];

    protected $casts = [
        'min_order_value' => 'decimal:2',
        'bank_details' => 'encrypted',
        'lead_time_days' => 'integer',
        'payment_terms_days' => 'integer',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
