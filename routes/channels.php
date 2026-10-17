<?php

use App\Models\ChatChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('chat.{channelId}', function ($user, int $channelId) {
    $channel = ChatChannel::find($channelId);
    if (!$channel) {
        return false;
    }
 
    $myContactId = $user->contact?->id;
    if ($myContactId === null) {
        return false;
    }
 
    $isMember = $channel->members()->where('contacts.id', $myContactId)->exists();
    if ($isMember) {
        return true;
    }
 
    // Admin override — group channels only, never DMs.
    if ($channel->type === 'group' && ($user->is_admin ?? false)) {
        return true;
    }
 
    return false;
});
