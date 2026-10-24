<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 8c.2c: the invitation email. Sent on demand (the invitee has no account yet) and queued.
 * The link is the only place the raw token ever appears; the database keeps just its hash.
 */
class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $tenantName,
        public readonly string $roleLabel,
        public readonly ?string $inviterName,
        public readonly string $expiresAtIso,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->inviterName !== null && $this->inviterName !== '' ? $this->inviterName : 'An administrator';

        return (new MailMessage())
            ->subject("You've been invited to join {$this->tenantName}")
            ->greeting('Hello,')
            ->line("{$who} has invited you to join {$this->tenantName} as {$this->roleLabel}.")
            ->action('Accept invitation', self::acceptUrl($this->token))
            ->line('This link works once and expires on '.Carbon::parse($this->expiresAtIso)->toDayDateTimeString().'.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }

    /**
     * The SPA route that accepts the invitation. In production the SPA is same-origin with the API,
     * so APP_URL is right; set app.frontend_url if the SPA is served from somewhere else (e.g. Vite in dev).
     */
    public static function acceptUrl(string $token): string
    {
        $base = config('app.frontend_url') ?: config('app.url');

        return rtrim((string) $base, '/').'/accept-invite/'.$token;
    }
}
