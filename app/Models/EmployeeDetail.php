<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'department_id',
        'job_position_id',
        'job_title',
        'manager_contact_id',
        'work_address',
        'work_location',
        'note',
        'resume_file_media_id',
        'private_email',
        'private_phone',
        'bank_account',
        'legal_name',
        'birthday',
        'place_of_birth',
        'birth_country',
        'gender',
        'emergency_contact_name',
        'emergency_contact_phone',
        'visa_no',
        'work_permit_no',
        'visa_document_media_id',
        'nationality',
        'identification_no',
        'ssn_no',
        'passport_no',
        'private_address',
        'home_work_distance',
        'marital_status',
        'dependent_children',
        'certificate_level',
        'field_of_study',
        'id_card_media_id',
        'driving_license_media_id',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'dependent_children' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'manager_contact_id');
    }

    public function resumeFile(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'resume_file_media_id');
    }

    public function visaDocument(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'visa_document_media_id');
    }

    public function idCard(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'id_card_media_id');
    }

    public function drivingLicense(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'driving_license_media_id');
    }
}