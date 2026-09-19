<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\Exceptions\StaleDealException;
use App\Domain\Deals\States\Lead;
use App\Domain\Deals\States\Lost;
use App\Domain\Deals\States\Negotiation;
use App\Domain\Deals\States\Proposal;
use App\Domain\Deals\States\Qualified;
use App\Domain\Deals\States\Won;
use App\Events\DealStageChanged;
use App\Models\Deal;
use App\Models\DealStageAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;


class TransitionDealAction
{
    public const STATE_MAP = [
        'lead' => Lead::class,
        'qualified' => Qualified::class,
        'proposal' => Proposal::class,
        'negotiation' => Negotiation::class,
        'won' => Won::class,
        'lost' => Lost::class,
    ];

    public function execute(Deal $deal, string $toStageSlug, int $expectedLockVersion, User $actingUser): Deal
    {
        return DB::transaction(function () use ($deal, $toStageSlug, $expectedLockVersion, $actingUser) {

            $deal = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();

            if ($deal->lock_version !== $expectedLockVersion) {
                throw new StaleDealException($deal);
            }

            $fromLabel = $deal->state->label();
            $toClass = self::STATE_MAP[$toStageSlug];


            $deal->state->transitionTo($toClass);

            $deal->lock_version = $expectedLockVersion + 1;
            $deal->save();

            DealStageAuditLog::create([
                'deal_id' => $deal->id,
                'user_id' => $actingUser->id,
                'from_state' => $fromLabel,
                'to_state' => $deal->state->label(),
                'transitioned_at' => now(),
            ]);

            event(new DealStageChanged($deal, $fromLabel, $deal->state->label()));

            return $deal;
        });
    }
}
