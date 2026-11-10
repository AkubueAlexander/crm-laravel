<?php

namespace App\Domain\Leads\Forms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


final class CaptchaVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return (string) config('services.turnstile.secret') !== '';
    }

    public static function siteKey(): ?string
    {
        $key = (string) config('services.turnstile.site_key');

        return $key === '' ? null : $key;
    }

    public static function passes(?string $token, ?string $ip): bool
    {
        $secret = (string) config('services.turnstile.secret');

        if ($secret === '') {
            return app()->environment(['local', 'testing']);
        }

        if ($token === null || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));

            if (! ($response->successful() && $response->json('success') === true)) {
                Log::warning('turnstile rejected', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }

            return $response->successful() && $response->json('success') === true;
        } catch (\Throwable $e) {
            Log::error('turnstile exception: '.$e->getMessage());

            return false;
        }
    }
}
