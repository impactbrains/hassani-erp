<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplenishmentPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'min_quantity' => 'decimal:3',
        'max_quantity' => 'decimal:3',
        'reorder_point' => 'decimal:3',
        'safety_stock' => 'decimal:3',
        'order_multiple' => 'decimal:3',
        'is_active' => 'boolean',
        'lead_time_days' => 'integer',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'preferred_supplier_id');
    }
}
