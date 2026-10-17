<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'cod_amount' => 'decimal:2',
        'collected_amount' => 'decimal:2',
        'assigned_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'rider_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class);
    }
}
