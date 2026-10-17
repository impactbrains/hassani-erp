<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMerge extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'merged_snapshot' => 'array',
        'moved_records' => 'array',
    ];

    public function survivor(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'survivor_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
