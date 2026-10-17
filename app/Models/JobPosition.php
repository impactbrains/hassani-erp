<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobPosition extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
