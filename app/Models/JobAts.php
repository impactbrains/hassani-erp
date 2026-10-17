<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobAts extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $table = 'jobs_ats';

    protected $fillable = [
        'title',
        'description',
        'store_id',
        'job_category_id',
        'total_positions',
        'start_date',
        'end_date',
        'status',
        'job_type_id',
        'work_experience',
        'show_salary',
        'salary_min',
        'salary_max',
        'ask_gender',
        'ask_country',
        'ask_address',
        'show_profile_image',
        'show_resume',
        'show_cover_letter',
        'show_terms_and_conditions',
        'created_by_contact_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'show_salary' => 'boolean',
            'ask_gender' => 'boolean',
            'ask_country' => 'boolean',
            'ask_address' => 'boolean',
            'show_profile_image' => 'boolean',
            'show_resume' => 'boolean',
            'show_cover_letter' => 'boolean',
            'show_terms_and_conditions' => 'boolean',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function jobCategory(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class);
    }
    public function jobType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(JobType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'created_by_contact_id');
    }

    public function atsSkills(): BelongsToMany
    {
        return $this->belongsToMany(AtsSkill::class, 'ats_skill_job_ats', 'job_ats_id', 'ats_skill_id');
    }

    public function formFields(): HasMany
    {
        return $this->hasMany(JobFormField::class, 'job_ats_id')->orderBy('sort_order');
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}