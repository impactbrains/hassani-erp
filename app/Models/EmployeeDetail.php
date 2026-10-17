<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR profile of an employee contact. CNIC and bank account are encrypted at rest.
 */
class EmployeeDetail extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['cnic', 'bank_account', 'cnic_hash'];

    protected $casts = [
        'joining_date' => 'date:Y-m-d',
        'probation_end_date' => 'date:Y-m-d',
        'confirmation_date' => 'date:Y-m-d',
        'exit_date' => 'date:Y-m-d',
        'cnic_expiry' => 'date:Y-m-d',
        'date_of_birth' => 'date:Y-m-d',
        'cnic' => 'encrypted',
        'bank_account' => 'encrypted',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_position_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'manager_contact_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_media_id');
    }
}
