<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    public function positions(): HasMany
    {
        return $this->hasMany(JobPosition::class);
    }
}
