<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Engagement;

/**
 * 9.5: adds tracking to an already rendered HTML email: every http(s) link becomes a signed click redirect and a
 * 1x1 open pixel is appended. The plain-text part is deliberately left alone. Never rewrites the unsubscribe link
 * (that one must stay a direct, working link), mailto:/tel:/anchors, or links that cannot be signed.
 *
 * Known limits (inherent to pixel tracking): image proxies and link scanners inflate opens and clicks, and clients
 * that block images never report an open. Treat the numbers as engagement signals, not exact counts.
 */
final class EngagementTracking
{
    public static function enabled(): bool
    {
        return (bool) config('services.campaigns.tracking', true);
    }

    public static function openUrl(int $tenantId, int $recipientId): string
    {
        return self::base().'/e/o/'.EngagementToken::makeOpen($tenantId, $recipientId);
    }

    public static function clickUrl(int $tenantId, int $recipientId, string $url): string
    {
        return self::base().'/e/c/'.EngagementToken::makeClick($tenantId, $recipientId, $url);
    }

    public static function apply(string $html, int $tenantId, int $recipientId): string
    {
        $rewritten = preg_replace_callback(
            '/(<a\b[^>]*?\bhref=)(["\'])(.*?)\2/is',
            static function (array $m) use ($tenantId, $recipientId): string {
                $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (! EngagementToken::isTrackableUrl($url) || str_contains($url, '/unsubscribe/') || str_contains($url, '/e/c/')) {
                    return $m[0];
                }

                $tracked = htmlspecialchars(self::clickUrl($tenantId, $recipientId, $url), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return $m[1].$m[2].$tracked.$m[2];
            },
            $html,
        );
        $html = $rewritten ?? $html;

        $pixel = '<img src="'.htmlspecialchars(self::openUrl($tenantId, $recipientId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'" width="1" height="1" alt="" style="display:none;border:0;">';

        $at = strripos($html, '</body>');

        return $at === false
            ? $html.$pixel
            : substr($html, 0, $at).$pixel.substr($html, $at);
    }

    private static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}