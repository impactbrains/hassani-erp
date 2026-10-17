<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundleComponent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'bundle_variant_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id');
    }
}
