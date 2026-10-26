<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Sending;

/**
 * 9.6c-2: the token inside the unsubscribe link of an AUTOMATION email. Same shape and path as UnsubscribeToken
 * (/unsubscribe/{token}) but it identifies (tenant, automation_enrollments row) and is signed under a different
 * context, so a campaign token can never be read as an enrollment token or the other way round.
 * Never expires: an unsubscribe link must keep working.
 */
final class EnrollmentUnsubscribeToken
{
    private const CONTEXT = 'automation-unsubscribe.v1';

    public static function make(int $tenantId, int $enrollmentId): string
    {
        $payload = self::b64encode($tenantId.'.'.$enrollmentId);

        return $payload.'.'.self::b64encode(self::signature($payload));
    }

    /** @return array{tenant_id: int, enrollment_id: int}|null null for anything malformed, tampered with or of another kind */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;

        $given = self::b64decode($signature);
        if ($given === null || ! hash_equals(self::signature($payload), $given)) {
            return null;
        }

        $decoded = self::b64decode($payload);
        if ($decoded === null || preg_match('/^(\d+)\.(\d+)$/', $decoded, $m) !== 1) {
            return null;
        }

        return ['tenant_id' => (int) $m[1], 'enrollment_id' => (int) $m[2]];
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/unsubscribe/'.$token;
    }

    private static function signature(string $payload): string
    {
        return hash_hmac('sha256', self::CONTEXT.'|'.$payload, self::key(), true);
    }

    private static function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is not set; cannot sign unsubscribe links.');
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