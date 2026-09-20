<?php

namespace App\Domain\Contacts\Matching;

use App\Models\Contact;
use App\Models\ContactMatchSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Two-stage duplicate detection (5.0):
 *   Stage 1: PostgreSQL narrows the tenant's contacts to a small candidate set using the
 *            pg_trgm GIN index (name) plus exact email / phone-key lookups.
 *   Stage 2: MatchScorer ranks the candidates in PHP; only those >= threshold are returned.
 *
 * Tenant isolation comes from Contact's BelongsToTenant global scope. No tenant id is accepted.
 */
final class DuplicateDetectionService
{
    /** Deliberately permissive: stage 2 does the real filtering. */
    private const TRIGRAM_CANDIDATE_THRESHOLD = 0.3;
    private const CANDIDATE_LIMIT = 50;
    private const RESULT_LIMIT = 10;

    public function __construct(private readonly MatchScorer $scorer) {}

    /**
     * @param  int|null  $excludeContactId  Exclude this contact (editing an existing record).
     * @param  int|null  $threshold  Override the tenant threshold (tests / previews).
     * @return Collection<int, DuplicateMatch> Sorted by score, descending.
     */
    public function findDuplicates(
        ContactCandidate $candidate,
        ?int $excludeContactId = null,
        ?int $threshold = null,
    ): Collection {
        $name = $candidate->normalizedName();
        $email = $candidate->normalizedEmail();
        $phoneKey = $candidate->phoneKey();

        // Too little signal to say anything useful (e.g. a single typed letter).
        if (mb_strlen($name) < 3 && $email === null && $phoneKey === null) {
            return collect();
        }

        $threshold ??= ContactMatchSettings::currentThreshold();

        return $this->fetchCandidates($name, $email, $phoneKey, $excludeContactId)
            ->map(fn (Contact $contact) => new DuplicateMatch(
                $contact,
                $this->scorer->score(
                    $candidate,
                    new ContactCandidate(
                        $contact->first_name,
                        $contact->last_name,
                        $contact->email,
                        $contact->phone,
                    ),
                ),
            ))
            ->filter(fn (DuplicateMatch $match) => $match->score->meets($threshold))
            ->sortByDesc(fn (DuplicateMatch $match) => $match->score->score)
            ->take(self::RESULT_LIMIT)
            ->values();
    }

    /** @return Collection<int, Contact> */
    private function fetchCandidates(
        string $name,
        ?string $email,
        ?string $phoneKey,
        ?int $excludeContactId,
    ): Collection {
        // set_config(..., is_local = true) scopes the trigram threshold to this transaction only,
        // so it can't leak into other queries on a reused connection.
        return DB::transaction(function () use ($name, $email, $phoneKey, $excludeContactId) {
            DB::select(
                "select set_config('pg_trgm.similarity_threshold', ?, true)",
                [(string) self::TRIGRAM_CANDIDATE_THRESHOLD],
            );

            return Contact::query()
                ->when($excludeContactId, fn ($q) => $q->whereKeyNot($excludeContactId))
                ->where(function ($q) use ($name, $email, $phoneKey) {
                    if ($name !== '') {
                        $q->orWhereRaw('name_normalized % ?', [$name]);
                    }
                    if ($email !== null) {
                        $q->orWhere('email_normalized', $email);
                    }
                    if ($phoneKey !== null) {
                        $q->orWhere('phone_key', $phoneKey);
                    }
                })
                ->when($name !== '', fn ($q) => $q->orderByRaw('similarity(name_normalized, ?) desc', [$name]))
                ->limit(self::CANDIDATE_LIMIT)
                ->get();
        });
    }
}
