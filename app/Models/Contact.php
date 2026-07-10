<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'email',
        'phone',
        'address',
        'city',
        'tax_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'contact_id');
    }

    public function scopeCustomers($query)
    {
        return $query->where('type', 'customer');
    }

    public function scopeSuppliers($query)
    {
        return $query->where('type', 'supplier');
    }

    public function scopeEmployees($query)
    {
        return $query->where('type', 'employee');
    }

    /**
     * Total spent across all completed (paid) POS orders, net of any
     * refunds issued against this contact. Draft/cancelled orders and
     * refund documents themselves are excluded from the "orders" side —
     * refunds are subtracted separately so the net figure is accurate.
     */
    public function totalSpent(): float
    {
        $orderedTotal = (float) $this->documents()
            ->where('type', 'pos_order')
            ->where('status', 'paid')
            ->sum('total');

        $refundedTotal = (float) $this->documents()
            ->where('type', 'pos_refund')
            ->sum('total');

        return round($orderedTotal - $refundedTotal, 2);
    }

    /**
     * Count of completed (paid) POS orders placed by this contact.
     */
    public function orderCount(): int
    {
        return $this->documents()
            ->where('type', 'pos_order')
            ->where('status', 'paid')
            ->count();
    }
}