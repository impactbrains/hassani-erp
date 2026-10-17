<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxCategory extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }
}
