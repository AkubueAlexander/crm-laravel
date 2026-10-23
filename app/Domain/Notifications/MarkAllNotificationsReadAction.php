<?php

namespace App\Domain\Notifications;

use App\Models\User;

class MarkAllNotificationsReadAction
{
    /** @return int number of notifications marked read */
    public function execute(User $user): int
    {
        return Inbox::unread($user)->update(['read_at' => now()]);
    }
}
