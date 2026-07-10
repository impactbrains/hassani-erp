<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Tax extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'rate',
        'type',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Calculate the tax amount for a given base amount.
     */
    public function calculateAmount(float $baseAmount): float
    {
        if ($this->type === 'fixed') {
            return (float) $this->rate;
        }

        // percentage
        return round($baseAmount * ((float) $this->rate / 100), 2);
    }
}