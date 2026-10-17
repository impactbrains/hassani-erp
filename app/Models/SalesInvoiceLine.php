<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_factor' => 'decimal:6',
        'base_quantity' => 'decimal:3',
        'list_price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'mrp' => 'decimal:2',
        'price_overridden' => 'boolean',
        'line_discount' => 'decimal:2',
        'cart_discount_share' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_inclusive' => 'boolean',
        'tax_amount' => 'decimal:2',
        'revenue_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'cost_amount' => 'decimal:2',
        'base_quantity_returned' => 'decimal:3',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function variantUnit(): BelongsTo
    {
        return $this->belongsTo(VariantUnit::class);
    }

    public function discountReason(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class, 'discount_reason_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
