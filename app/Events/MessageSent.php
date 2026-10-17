<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired from ChatController::storeMessage() right after a message is
 * saved. Broadcasts on a private channel scoped to the specific chat
 * channel_id — routes/channels.php's Broadcast::channel('chat.{channelId}',
 * ...) callback is what actually enforces who's allowed to receive
 * this event; this class only defines WHAT gets sent and WHERE.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message,
    ) {
        $this->message->load(['sender:id,name', 'attachments']);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->message->channel_id),
        ];
    }

    /**
     * Custom event name on the JS side (Echo listens for
     * 'message.sent' — the leading dot in broadcastAs() means "don't
     * namespace this under the full PHP class path").
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'channel_id' => $this->message->channel_id,
            'body' => $this->message->body,
            'sender' => $this->message->sender ? [
                'id' => $this->message->sender->id,
                'name' => $this->message->sender->name,
            ] : null,
            'attachments' => $this->message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'is_image' => $a->is_image,
                'url' => $a->url(),
            ]),
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}