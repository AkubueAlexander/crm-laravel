<?php

namespace App\Domain\Contacts\Matching;

/**
 * Framework-agnostic value object: "a contact as typed into the form" or
 * "a stored contact", reduced to the fields that matter for matching.
 */
final readonly class ContactCandidate
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}

    public function normalizedName(): string
    {
        return self::normalizeText(trim(($this->firstName ?? '').' '.($this->lastName ?? '')));
    }

    public function normalizedEmail(): ?string
    {
        $email = mb_strtolower(trim($this->email ?? ''));

        return $email === '' ? null : $email;
    }

    /** Last 10 digits, or null if fewer than 7 digits. Mirrors contacts.phone_key. */
    public function phoneKey(): ?string
    {
        $digits = preg_replace('/\D+/', '', $this->phone ?? '') ?? '';

        return strlen($digits) >= 7 ? substr($digits, -10) : null;
    }

    public static function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
