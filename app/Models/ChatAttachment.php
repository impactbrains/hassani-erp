<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function getIsImageAttribute(): bool
    {
        return $this->mime_type !== null && str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Files are stored outside the public webroot under
     * storage/app/chat-attachments/ (NOT public/uploads/ like Media),
     * so they're only reachable through the authenticated download
     * route below — chat attachments are private-by-default (only
     * channel members should ever see them), unlike the Media library
     * where everything is already implicitly public once uploaded.
     */
    public function url(): string
    {
        return route('chat.attachments.download', ['attachment' => $this->id]);
    }
}