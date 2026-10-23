<?php

namespace App\Notifications;

use App\Models\Activity;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an activity's owner when activities:flag-overdue flags it (one send per
 * flag transition). Database + broadcast. The payload always carries tenant_id so
 * every read path can filter by tenant even if a user later belongs to several.
 */
class ActivityOverdueNotification extends Notification
{
    public const KIND = 'activity_overdue';

    public function __construct(private readonly Activity $activity)
    {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $alias = $this->activity->activityable_type;

        return [
            'kind' => self::KIND,
            'tenant_id' => (int) $this->activity->tenant_id,
            'activity_id' => (int) $this->activity->id,
            'activity_type' => $this->activity->type,
            'subject' => $this->activity->subject,
            'due_at' => $this->activity->due_at?->toIso8601String(),
            'target_type' => is_string($alias) && array_key_exists($alias, Activity::TARGETS) ? $alias : null,
            'target_id' => $this->activity->activityable_id !== null ? (int) $this->activity->activityable_id : null,
        ];
    }

    /**
     * Tiny payload; broadcast synchronously so the bell updates without needing a
     * queue worker. The client only uses the event as an "invalidate" signal.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
