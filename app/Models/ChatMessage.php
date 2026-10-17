<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'contact_id',
        'body',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'channel_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }
}