<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Class name is AtsSkill (not Skill) to avoid collision with the
 * existing App\Models\Skill used by the HR employee-profile feature —
 * see the migration's note for why these are deliberately separate
 * concepts.
 */
class AtsSkill extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $table = 'ats_skills';

    protected $fillable = ['name'];

    public function jobCategories(): BelongsToMany
    {
        return $this->belongsToMany(JobCategory::class, 'ats_skill_job_category');
    }
}