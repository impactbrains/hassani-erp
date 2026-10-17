<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSwapRequest extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function rosterEntry(): BelongsTo
    {
        return $this->belongsTo(RosterEntry::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'requested_by_contact_id');
    }

    public function covering(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'covering_contact_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
