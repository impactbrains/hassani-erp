<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'work_date' => 'date:Y-m-d',
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'is_corrected' => 'boolean',
        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'worked_minutes' => 'integer',
        'overtime_minutes' => 'integer',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function rosterEntry(): BelongsTo
    {
        return $this->belongsTo(RosterEntry::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
