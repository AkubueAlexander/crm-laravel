<?php

namespace App\Domain\Contacts\Matching;

use App\Models\Contact;

final readonly class DuplicateMatch
{
    public function __construct(
        public Contact $contact,
        public MatchScore $score,
    ) {}
}
