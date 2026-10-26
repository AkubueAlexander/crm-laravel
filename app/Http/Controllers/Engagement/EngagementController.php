<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engagement;

use App\Domain\Marketing\Engagement\EngagementRecorder;
use App\Domain\Marketing\Engagement\EngagementToken;
use App\Domain\Marketing\Sending\UnsubscribeToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * 9.5: public engagement endpoints. No session: the signed token (or the HMAC signature) is the credential.
 *
 * - GET /e/o/{token}: open pixel. ALWAYS answers with the GIF, valid token or not, so nothing can be probed.
 * - GET /e/c/{token}: click redirect to the URL signed inside the token. Anything invalid is a plain 404.
 * - POST /webhooks/email-events: provider-agnostic bounce/complaint intake. A thin provider adapter (or the provider's
 *   own webhook transformer) posts this normalized body, signed with HMAC-SHA256 of the RAW body (hex) in X-Signature
 *   using services.email_events.secret (env EMAIL_EVENTS_SECRET):
 *     { "events": [ { "type": "bounce" | "complaint", "recipient": "<value of the X-Campaign-Recipient header>",
 *                     "permanent": true } ] }
 *   "permanent" only matters for bounces and defaults to true; a soft bounce is counted but does not suppress.
 */
final class EngagementController extends Controller
{
    public const MAX_EVENTS = 100;

    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $token): Response
    {
        $parsed = EngagementToken::parseOpen($token);

        if ($parsed !== null) {
            try {
                EngagementRecorder::opened($parsed['tenant_id'], $parsed['recipient_id']);
            } catch (Throwable $e) {
                report($e); // a database hiccup must never break the image
            }
        }

        return response((string) base64_decode(self::PIXEL), 200)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function click(string $token): SymfonyResponse
    {
        $parsed = EngagementToken::parseClick($token);

        if ($parsed === null) {
            return response(
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">'
                .'<title>Link not valid</title></head><body style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:64px auto;padding:0 16px;">'
                .'<h1 style="font-size:20px;">Link not valid</h1><p>This link is not valid.</p></body></html>',
                404,
            )->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
        }

        try {
            EngagementRecorder::clicked($parsed['tenant_id'], $parsed['recipient_id']);
        } catch (Throwable $e) {
            report($e); // never block the visitor on a recording failure
        }

        return redirect()->away($parsed['url'], 302)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function events(Request $request): JsonResponse
    {
        $secret = (string) config('services.email_events.secret', '');
        if ($secret === '') {
            abort(404); // the feature is off until a secret is configured
        }

        $raw = $request->getContent();
        $given = (string) $request->header('X-Signature', '');
        if (! hash_equals(hash_hmac('sha256', $raw, $secret), $given)) {
            abort(401, 'Invalid signature.');
        }

        $payload = json_decode($raw, true);
        $events = is_array($payload) ? ($payload['events'] ?? null) : null;

        if (! is_array($events) || ! array_is_list($events) || count($events) > self::MAX_EVENTS) {
            return response()->json([
                'message' => 'The events field must be a list of at most '.self::MAX_EVENTS.' items.',
                'errors' => ['events' => ['The events field must be a list of at most '.self::MAX_EVENTS.' items.']],
                'code' => 'validation_failed',
            ], 422);
        }

        $processed = 0;
        $ignored = 0;

        foreach ($events as $event) {
            $type = is_array($event) ? ($event['type'] ?? null) : null;
            $recipient = is_array($event) ? ($event['recipient'] ?? null) : null;
            $parsed = is_string($recipient) ? UnsubscribeToken::parse($recipient) : null;

            if ($parsed === null || ! in_array($type, ['bounce', 'complaint'], true)) {
                $ignored++;
                continue;
            }

            $found = $type === 'bounce'
                ? EngagementRecorder::bounced($parsed['tenant_id'], $parsed['recipient_id'], ($event['permanent'] ?? true) !== false)
                : EngagementRecorder::complained($parsed['tenant_id'], $parsed['recipient_id']);

            $found ? $processed++ : $ignored++;
        }

        return response()->json(['processed' => $processed, 'ignored' => $ignored]);
    }
}