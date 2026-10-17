<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class JobCategory extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = ['name'];

    public function atsSkills(): BelongsToMany
    {
        return $this->belongsToMany(AtsSkill::class, 'ats_skill_job_category');
    }
}