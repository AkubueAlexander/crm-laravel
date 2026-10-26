<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Symfony\Component\Mime\Email;

/**
 * 9.4c: one campaign email for one recipient. Everything is already rendered by SendRenderer (footer,
 * unsubscribe link, List-Unsubscribe headers); this class only carries it to the mailer. Not queued itself:
 * the chunk job is the queue.
 */
final class CampaignEmail extends Mailable
{
    /** @param array<string, string> $extraHeaders */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $htmlBody,
        public readonly string $textBody,
        public readonly array $extraHeaders = [],
    ) {
        $text = $this->textBody;
        // Plain-text alternative next to the HTML part.
        $this->withSymfonyMessage(function (Email $message) use ($text): void {
            $message->text($text);
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->extraHeaders);
    }
}