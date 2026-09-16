<?php

namespace App\Domain\Deals\States;

class Proposal extends DealState
{
    public function label(): string
    {
        return 'Proposal';
    }
}
