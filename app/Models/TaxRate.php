<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRate extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'rate' => 'decimal:4',
        'is_inclusive' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class, 'tax_category_id');
    }
}
