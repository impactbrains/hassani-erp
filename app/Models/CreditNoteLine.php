<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'base_quantity' => 'decimal:3',
        'tax_amount' => 'decimal:2',
        'revenue_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'cost_amount' => 'decimal:2',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
