<?php

namespace App\Domain\Deals\States;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class DealState extends State
{
    abstract public function label(): string;

    /**
     * 3.0: the pipeline's valid stage transitions live here, once. The
     * transition domain action (next chunk) is the single entry point that
     * calls through this config — POST /api/v1/deals/{deal}/transition can
     * never move a deal to an arbitrary stage the config doesn't allow.
     */

    /**
     * @throws \Spatie\ModelStates\Exceptions\InvalidConfig
     */
    public static function config(): StateConfig
    {

        return parent::config()
            ->default(Lead::class)
            ->allowTransition(Lead::class, Qualified::class)
            ->allowTransition(Lead::class, Lost::class)
            ->allowTransition(Qualified::class, Proposal::class)
            ->allowTransition(Qualified::class, Lost::class)
            ->allowTransition(Proposal::class, Negotiation::class)
            ->allowTransition(Proposal::class, Lost::class)
            ->allowTransition(Negotiation::class, Won::class)
            ->allowTransition(Negotiation::class, Lost::class);
        // Won/Lost are terminal for now — no outgoing transitions.
        // Reopening a Lost deal back into the pipeline is a real future
        // need, but doing it correctly means consulting deal_stage_audit_log
        // (3.6) to know which stage to return to, not a blind Lost -> Lead
        // jump that discards that context. Deferred rather than guessed at.
    }
}
