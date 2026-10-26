<?php

declare(strict_types=1);

namespace App\Http\Controllers\Unsubscribe;

use App\Domain\Marketing\Sending\EmailSuppressions;
use App\Domain\Marketing\Sending\UnsubscribeToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * 9.4c: the public unsubscribe pages. No session and no login: the signed token is the credential.
 * - GET only shows a confirmation page and changes nothing (mail scanners and link prefetchers open links).
 * - POST suppresses the address. It is also what mail clients call for RFC 8058 one-click unsubscribe.
 * - Every invalid, tampered or unknown token gets the same 404 page, so nothing can be learned by guessing.
 */
final class UnsubscribeController extends Controller
{
    public function show(string $token): Response
    {
        $recipient = $this->resolve($token);
        if ($recipient === null) {
            return $this->notFound();
        }

        $masked = e(self::mask((string) $recipient->email));
        $action = e('/unsubscribe/'.$token);

        return $this->page(200, 'Unsubscribe',
            '<p>Stop receiving emails sent to <strong>'.$masked.'</strong>?</p>'
            .'<form method="post" action="'.$action.'"><button type="submit">Unsubscribe</button></form>');
    }

    public function confirm(string $token): Response
    {
        $recipient = $this->resolve($token);
        if ($recipient === null) {
            return $this->notFound();
        }

        // Idempotent: unsubscribing twice is the same as once.
        EmailSuppressions::suppress(
            (int) $recipient->tenant_id,
            (string) $recipient->email,
            'unsubscribed',
            $recipient->campaign_id === null ? null : (int) $recipient->campaign_id,
        );

        return $this->page(200, 'You are unsubscribed', '<p>You will no longer receive emails from this sender.</p>');
    }

    private function resolve(string $token): ?object
    {
        $enrollment = \App\Domain\Marketing\Sending\EnrollmentUnsubscribeToken::parse($token);
        if ($enrollment !== null) {
            // 9.6c-2: automation emails carry an enrollment token (the tenant comes from the signed token).
            $row = DB::table('automation_enrollments')
                ->where('tenant_id', $enrollment['tenant_id'])
                ->where('id', $enrollment['enrollment_id'])
                ->first(['tenant_id', 'email']);

            return $row === null ? null : (object) ['tenant_id' => $row->tenant_id, 'campaign_id' => null, 'email' => $row->email];
        }

        $parsed = UnsubscribeToken::parse($token);
        if ($parsed === null) {
            return null;
        }

        // Explicit tenant predicate: the tenant comes from the signed token, never from the request.
        return DB::table('campaign_recipients')
            ->where('tenant_id', $parsed['tenant_id'])
            ->where('id', $parsed['recipient_id'])
            ->first(['tenant_id', 'campaign_id', 'email']);
    }

    private function notFound(): Response
    {
        return $this->page(404, 'Link not valid', '<p>This unsubscribe link is not valid.</p>');
    }

    /** $bodyHtml must already be escaped by the caller. */
    private function page(int $status, string $title, string $bodyHtml): Response
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex, nofollow">'
            .'<title>'.e($title).'</title></head>'
            .'<body style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:64px auto;padding:0 16px;color:#1c1917;">'
            .'<h1 style="font-size:20px;">'.e($title).'</h1>'.$bodyHtml
            .'</body></html>';

        return response($html, $status)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}