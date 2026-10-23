<?php

namespace App\Domain\Search;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * 8b.1 global search. Every query runs through the models, so the BelongsToTenant
 * global scope and SoftDeletes apply: results can never cross tenants. Each group is
 * gated by its own <alias>.view permission; a group the actor cannot see is omitted.
 */
final class SearchService
{
    public const PER_GROUP = 5;

    /**
     * @return array<string, list<array{id: int, label: string, sublabel: string|null}>>
     */
    public function search(User $actor, string $term): array
    {
        $escaped = self::escape($term);
        $like = '%' . $escaped . '%';
        $prefix = $escaped . '%';
        $gate = Gate::forUser($actor);

        $groups = [];

        if ($gate->allows('leads.view')) {
            $groups['leads'] = $this->leads($like, $prefix);
        }
        if ($gate->allows('contacts.view')) {
            $groups['contacts'] = $this->contacts($like, $prefix);
        }
        if ($gate->allows('accounts.view')) {
            $groups['accounts'] = $this->accounts($like, $prefix);
        }
        if ($gate->allows('deals.view')) {
            $groups['deals'] = $this->deals($like, $prefix);
        }

        return $groups;
    }

    /** Escape LIKE metacharacters so user input is always matched literally. */
    private static function escape(string $term): string
    {
        return addcslashes(mb_strtolower($term), '\\%_');
    }

    /** @return list<array{id: int, label: string, sublabel: string|null}> */
    private function leads(string $like, string $prefix): array
    {
        $name = "concat_ws(' ', first_name, last_name)";

        return Lead::query()
            ->where(fn ($w) => $w
                ->whereRaw("{$name} ilike ?", [$like])
                ->orWhere('email', 'ilike', $like)
                ->orWhere('company', 'ilike', $like))
            ->orderByRaw("case when {$name} ilike ? then 0 else 1 end", [$prefix])
            ->orderBy('id')
            ->limit(self::PER_GROUP)
            ->get(['id', 'first_name', 'last_name', 'email', 'company'])
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'label' => trim(($l->first_name ?? '') . ' ' . ($l->last_name ?? '')) ?: ($l->email ?? ('Lead #' . $l->id)),
                'sublabel' => $l->company ?: $l->email,
            ])
            ->all();
    }

    /** @return list<array{id: int, label: string, sublabel: string|null}> */
    private function contacts(string $like, string $prefix): array
    {
        // name_normalized / email_normalized are the generated, trigram-indexed columns (5.0).
        return Contact::query()
            ->where(fn ($w) => $w
                ->where('name_normalized', 'like', $like)
                ->orWhere('email_normalized', 'like', $like))
            ->orderByRaw('case when name_normalized like ? then 0 else 1 end', [$prefix])
            ->orderBy('id')
            ->limit(self::PER_GROUP)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (Contact $c) => [
                'id' => $c->id,
                'label' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ($c->email ?? ('Contact #' . $c->id)),
                'sublabel' => $c->email,
            ])
            ->all();
    }

    /** @return list<array{id: int, label: string, sublabel: string|null}> */
    private function accounts(string $like, string $prefix): array
    {
        return Account::query()
            ->where(fn ($w) => $w
                ->where('name', 'ilike', $like)
                ->orWhere('industry', 'ilike', $like)
                ->orWhere('website', 'ilike', $like))
            ->orderByRaw('case when name ilike ? then 0 else 1 end', [$prefix])
            ->orderBy('id')
            ->limit(self::PER_GROUP)
            ->get(['id', 'name', 'industry'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'label' => $a->name,
                'sublabel' => $a->industry,
            ])
            ->all();
    }

    /** @return list<array{id: int, label: string, sublabel: string|null}> */
    private function deals(string $like, string $prefix): array
    {
        return Deal::query()
            ->where('name', 'ilike', $like)
            ->orderByRaw('case when name ilike ? then 0 else 1 end', [$prefix])
            ->orderBy('id')
            ->limit(self::PER_GROUP)
            ->get(['id', 'name'])
            ->map(fn (Deal $d) => [
                'id' => $d->id,
                'label' => $d->name,
                'sublabel' => null,
            ])
            ->all();
    }
}
