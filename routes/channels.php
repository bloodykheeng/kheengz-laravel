<?php

use Illuminate\Support\Facades\Broadcast;

// Primary keys are ULIDs, so compare as strings: casting to int collapses every
// ULID to 0 and would authorize any user for any other user's channel.
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

// Presence channel for live online/offline awareness.
Broadcast::channel('online', function ($user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->getRoleNames()->last(),
        'photo_url' => $user->photo_url ?? null,
    ];
});

// Public channels never reach this file. The storefront's (an order's payment answered, by its
// reference, status only) arrive with the orders module in Phase 1.
