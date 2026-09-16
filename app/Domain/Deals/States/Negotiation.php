<?php

namespace App\Domain\Deals\States;

class Negotiation extends DealState
{
    public function label(): string
    {
        return 'Negotiation';
    }
}
