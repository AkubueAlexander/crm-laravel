<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Engagement;

use InvalidArgumentException;

/**
 * 9.5: signed tokens for the open pixel (/e/o/{token}) and the click redirect (/e/c/{token}).
 * HMAC-SHA256 keyed by APP_KEY, never expiring (old emails keep working). The kind is part of the signed payload, so
 * an open token can never be replayed as a click token or the other way round, and neither is an unsubscribe token.
 * The click token carries the destination URL INSIDE the signed payload: the redirect target can only be a URL this
 * application signed itself, which is what keeps /e/c/ from being an open redirect.
 */
final class EngagementToken
{
    private const CONTEXT = 'campaign-engagement.v1';

    public const MAX_URL = 1500;

    public static function makeOpen(int $tenantId, int $recipientId): string
    {
        return self::sign('o.'.$tenantId.'.'.$recipientId);
    }

    public static function makeClick(int $tenantId, int $recipientId, string $url): string
    {
        if (! self::isTrackableUrl($url)) {
            throw new InvalidArgumentException('Only absolute http(s) URLs of up to '.self::MAX_URL.' characters can be tracked.');
        }

        return self::sign('c.'.$tenantId.'.'.$recipientId.'.'.self::b64encode($url));
    }

    /** No whitespace, angle brackets or quotes: the value ends up in an href and a Location header. */
    public static function isTrackableUrl(string $url): bool
    {
        return strlen($url) <= self::MAX_URL && preg_match('#^https?://[^\s<>"\']+$#i', $url) === 1;
    }

    /** @return array{tenant_id: int, recipient_id: int}|null */
    public static function parseOpen(string $token): ?array
    {
        $payload = self::verified($token);
        if ($payload === null || preg_match('/^o\.(\d+)\.(\d+)$/', $payload, $m) !== 1) {
            return null;
        }

        return ['tenant_id' => (int) $m[1], 'recipient_id' => (int) $m[2]];
    }

    /** @return array{tenant_id: int, recipient_id: int, url: string}|null */
    public static function parseClick(string $token): ?array
    {
        $payload = self::verified($token);
        if ($payload === null || preg_match('/^c\.(\d+)\.(\d+)\.([A-Za-z0-9_-]+)$/', $payload, $m) !== 1) {
            return null;
        }

        $url = self::b64decode($m[3]);
        if ($url === null || ! self::isTrackableUrl($url)) {
            return null;
        }

        return ['tenant_id' => (int) $m[1], 'recipient_id' => (int) $m[2], 'url' => $url];
    }

    private static function sign(string $payload): string
    {
        $p = self::b64encode($payload);

        return $p.'.'.self::b64encode(self::signature($p));
    }

    /** The decoded payload when the signature matches, otherwise null. */
    private static function verified(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$p, $s] = $parts;

        $given = self::b64decode($s);
        if ($given === null || ! hash_equals(self::signature($p), $given)) {
            return null;
        }

        return self::b64decode($p);
    }

    private static function signature(string $p): string
    {
        return hash_hmac('sha256', self::CONTEXT.'|'.$p, self::key(), true);
    }

    private static function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is not set; cannot sign tracking links.');
        }

        return $key;
    }

    private static function b64encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64decode(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}