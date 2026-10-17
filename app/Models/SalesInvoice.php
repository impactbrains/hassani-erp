<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A posted sale. A POS receipt is an invoice of kind "pos". Never edited after posting;
 * corrections are credit notes that reference it.
 */
class SalesInvoice extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'credit_extended' => 'decimal:2',
        'cost_complete' => 'boolean',
        'rounding_adjustment' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_credited' => 'decimal:2',
        'cost_total' => 'decimal:2',
        'due_date' => 'date:Y-m-d',
        'business_date' => 'date:Y-m-d',
        'posted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sales_agent_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }


    public function allocations(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    /** Amount still owed: total less payments and credit notes applied. */
    public function balanceDue(): string
    {
        return Decimal::money(Decimal::sub(Decimal::sub($this->total, $this->amount_paid), $this->amount_credited));
    }
}
