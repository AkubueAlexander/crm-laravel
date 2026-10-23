<?php

namespace App\Domain\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class MarkNotificationReadAction
{
    public function execute(User $user, string $id): DatabaseNotification
    {
        // A non-uuid would raise a Postgres type error (500) instead of a clean 404.
        abort_unless(Str::isUuid($id), 404, 'Notification not found.');

        // Resolved through the inbox scope: another user's or another tenant's row is a 404.
        $notification = Inbox::for($user)->whereKey($id)->firstOrFail();

        $notification->markAsRead();

        return $notification;
    }
}
