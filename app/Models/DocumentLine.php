<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'variant_id',
        'description',
        'quantity',
        'unit_price',
        'tax_id',
        'tax_amount',
        'discount_percent',
        'discount_amount',
        'line_total',
        'is_custom',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'is_custom' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * Recomputes tax_amount and line_total from quantity, unit_price,
     * discount, and the associated tax rate. Called whenever any of those
     * inputs change — line_total is always derived, never hand-set.
     */
    public function recalculate(): void
    {
        $gross = (float) $this->quantity * (float) $this->unit_price;

        $discountAmount = 0.0;
        if ($this->discount_percent > 0) {
            $discountAmount = round($gross * ((float) $this->discount_percent / 100), 2);
        } elseif ($this->discount_amount > 0) {
            $discountAmount = (float) $this->discount_amount;
        }

        $netBeforeTax = max(0, $gross - $discountAmount);

        $taxAmount = 0.0;
        if ($this->tax_id && $this->tax) {
            $taxAmount = $this->tax->calculateAmount($netBeforeTax);
        }

        $this->discount_amount = $discountAmount;
        $this->tax_amount = $taxAmount;
        $this->line_total = round($netBeforeTax + $taxAmount, 2);
    }
}