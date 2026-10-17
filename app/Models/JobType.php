<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobType extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = ['name'];

    public function jobsAts(): HasMany
    {
        return $this->hasMany(JobAts::class, 'job_type_id');
    }
}