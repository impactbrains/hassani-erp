<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A double-entry journal. Posted entries are immutable (enforced by the database).
 */
class JournalEntry extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'entry_date' => 'date:Y-m-d',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }


    public function reversal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(JournalEntry::class, 'reversal_of_id');
    }
}
