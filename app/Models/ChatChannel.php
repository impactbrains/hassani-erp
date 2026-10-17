<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatChannel extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'created_by_contact_id',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'created_by_contact_id');
    }

    public function members(): BelongsToMany
    {
        // Explicit FK column names — the pivot column is `channel_id`
        // (see migration), not Eloquent's guessed `chat_channel_id`
        // (based on this model's class name). Without these two extra
        // arguments, Eloquent silently builds queries against a column
        // that doesn't exist.
        return $this->belongsToMany(Contact::class, 'chat_channel_members', 'channel_id', 'contact_id')
            ->withPivot(['can_manage', 'is_notifications_muted', 'is_restricted', 'joined_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'channel_id')->orderBy('created_at');
    }

    public function scopeGroups($query)
    {
        return $query->where('type', 'group');
    }

    public function scopeDms($query)
    {
        return $query->where('type', 'dm');
    }

    public static function findExistingDm(int $contactIdA, int $contactIdB): ?self
    {
        return self::query()
            ->where('type', 'dm')
            ->whereHas('members', fn ($q) => $q->where('contacts.id', $contactIdA))
            ->whereHas('members', fn ($q) => $q->where('contacts.id', $contactIdB))
            ->first();
    }
}