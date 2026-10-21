<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One break inside a working day. Who typed it in is always kept. */
class AttendanceBreak extends Model
{
    use BelongsToCompany;

    public const TYPES = ['meal', 'prayer', 'other'];

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'work_date' => 'date:Y-m-d',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_unpaid' => 'boolean',
        'minutes' => 'integer',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
