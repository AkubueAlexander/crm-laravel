<?php

namespace App\Domain\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The single place that defines "which notifications may this user see".
 * Scoped to the user AND to the tenant stored in the notification data, so a user
 * who later belongs to several tenants never sees one tenant's items under another.
 * The data->>'tenant_id' predicate matches the expression indexes on notifications.
 */
class Inbox
{
    public static function for(User $user): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('data->tenant_id', (string) $user->tenant_id);
    }

    public static function unread(User $user): Builder
    {
        return self::for($user)->whereNull('read_at');
    }
}
