<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Templates;

use Illuminate\Support\Str;

/**
 * Renders a template's Markdown + subject for one recipient. The editor preview (9.3) and the
 * send job (9.4) both call this, so what the author previews is what recipients get.
 *
 * Safety: raw HTML in the Markdown is stripped and unsafe link schemes are dropped. Variables are
 * substituted AFTER Markdown rendering (so a contact name cannot inject Markdown) and HTML-escaped
 * in the body. Subject values lose line breaks (no header injection). Unknown variables render empty.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string, string>  $values  see TemplateVariables::valuesFor()
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(string $subject, string $body, array $values): array
    {
        $fragment = Str::markdown($body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = self::substitute($fragment, $values, static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $subjectOut = self::substitute($subject, $values, static fn (string $v): string => (string) preg_replace('/[\r\n]+/', ' ', $v));

        return [
            'subject' => trim($subjectOut),
            'html' => self::wrap($html),
            'text' => self::toText($html),
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @param  callable(string): string  $escape
     */
    private static function substitute(string $text, array $values, callable $escape): string
    {
        return (string) preg_replace_callback(
            TemplateVariables::PATTERN,
            static function (array $m) use ($values, $escape): string {
                $name = $m[1];
                if (! array_key_exists($name, TemplateVariables::VARIABLES)) {
                    return '';
                }

                return $escape((string) ($values[$name] ?? ''));
            },
            $text,
        );
    }

    private static function wrap(string $fragment): string
    {
        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            .'<body style="margin:0;padding:24px;background:#f5f5f4;">'
            .'<div style="max-width:600px;margin:0 auto;padding:24px;background:#ffffff;'
            .'font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1c1917;">'
            .$fragment
            .'</div></body></html>';
    }

    private static function toText(string $html): string
    {
        // Keep link targets readable in the plain-text alternative: "Docs (https://...)".
        $text = (string) preg_replace_callback(
            '#<a\s+[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(strip_tags($m[2]));

                return ($label === '' || $label === $url) ? $url : $label.' ('.$url.')';
            },
            $html,
        );

        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#</(p|h[1-6]|blockquote|pre|ul|ol)>#i', "\n\n", $text);
        $text = (string) preg_replace('#</li>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}