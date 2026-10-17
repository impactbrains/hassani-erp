<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Badge extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'employee_badge');
    }
}
