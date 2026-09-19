<?php

namespace App\Domain\Deals\States;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class DealState extends State
{
    abstract public function label(): string;


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

    }
}
