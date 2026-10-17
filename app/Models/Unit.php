<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'allows_fraction' => 'boolean',
        'is_active' => 'boolean',
        'decimals' => 'integer',
    ];
}
