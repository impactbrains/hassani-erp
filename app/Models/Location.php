<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'address',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Current stock on hand for a given variant at this location,
     * computed live from the movement ledger.
     */
    public function stockOnHand(int $variantId): float
    {
        return (float) $this->stockMovements()
            ->where('variant_id', $variantId)
            ->sum('quantity_change');
    }
}