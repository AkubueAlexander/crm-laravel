<?php

namespace App\Domain\Contacts\Matching;

final readonly class MatchScore
{
    /** @param list<string> $reasons Human-readable, shown in the DuplicateWarning UI. */
    public function __construct(
        public int $score,
        public array $reasons,
    ) {}

    public function meets(int $threshold): bool
    {
        return $this->score >= $threshold;
    }
}
