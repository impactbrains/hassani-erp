<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Training extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'is_mandatory' => 'boolean',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeTraining::class);
    }
}
