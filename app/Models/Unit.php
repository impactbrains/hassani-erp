<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'short_code',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'unit_id');
    }
}