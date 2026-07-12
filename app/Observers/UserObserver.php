<?php

namespace App\Observers;

use App\Models\Contact;
use App\Models\User;

class UserObserver
{
    /**
     * When a new User is created (registration, admin-created, seeded, etc.),
     * automatically create a matching customer Contact linked back to them.
     */
    public function created(User $user): void
    {
        Contact::firstOrCreate(
            ['user_id' => $user->id],
            [
                'type' => 'customer',
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => true,
            ]
        );
    }

    /**
     * Keep the linked Contact's name/email in sync if the user updates them.
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged(['name', 'email']) && $user->contact) {
            $user->contact->update([
                'name' => $user->name,
                'email' => $user->email,
            ]);
        }
    }
}