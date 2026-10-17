<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBatch extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'expiry_date' => 'date:Y-m-d',
        'manufactured_on' => 'date:Y-m-d',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function levels(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockLevel::class, 'batch_id');
    }
}
