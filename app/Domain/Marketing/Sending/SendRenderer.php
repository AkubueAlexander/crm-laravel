<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Sending;

use App\Domain\Marketing\Templates\TemplateRenderer;
use InvalidArgumentException;

/**
 * 9.4b: renders one email for a real send. It wraps TemplateRenderer (so the editor preview and the send
 * share the same rendering), then adds the mandatory footer (who sent it, optional postal address,
 * unsubscribe link) and the List-Unsubscribe headers (RFC 2369 + RFC 8058 one-click).
 * TemplateRenderer::render() itself is unchanged: the preview never shows a footer.
 */
final class SendRenderer
{
    private const WRAP_SUFFIX = '</div></body></html>';

    /**
     * @param  array<string, string>  $values  see TemplateVariables::valuesFor()
     * @return array{subject: string, html: string, text: string, headers: array<string, string>}
     */
    public static function render(
        string $subject,
        string $body,
        array $values,
        string $unsubscribeUrl,
        string $senderName,
        ?string $postalAddress = null,
    ): array {
        self::assertUrl($unsubscribeUrl);

        $rendered = TemplateRenderer::render($subject, $body, $values);

        $sender = self::oneLine($senderName);
        $address = $postalAddress === null ? '' : self::oneLine($postalAddress);
        $reason = $sender === ''
            ? 'You are receiving this email because you are on our mailing list.'
            : 'You are receiving this email because you are a contact of '.$sender.'.';

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $footerHtml = '<p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #e7e5e4;'
            .'font-size:12px;line-height:1.5;color:#78716c;">'
            .$e($reason)
            .($address !== '' ? '<br>'.$e($address) : '')
            .'<br><a href="'.$e($unsubscribeUrl).'" style="color:#78716c;">Unsubscribe</a>'
            .'</p>';

        $html = $rendered['html'];
        if (str_ends_with($html, self::WRAP_SUFFIX)) {
            $html = substr($html, 0, -strlen(self::WRAP_SUFFIX)).$footerHtml.self::WRAP_SUFFIX;
        } else {
            $html .= $footerHtml;
        }

        $text = $rendered['text']
            ."\n\n-- \n".$reason
            .($address !== '' ? "\n".$address : '')
            ."\nUnsubscribe: ".$unsubscribeUrl;

        return [
            'subject' => $rendered['subject'],
            'html' => $html,
            'text' => $text,
            'headers' => [
                'List-Unsubscribe' => '<'.$unsubscribeUrl.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        ];
    }

    private static function assertUrl(string $url): void
    {
        // No whitespace or angle brackets: the value goes into a header and an href.
        if (preg_match('#^https?://[^\s<>"\']+$#i', $url) !== 1) {
            throw new InvalidArgumentException('The unsubscribe URL must be an absolute http(s) URL.');
        }
    }

    private static function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}