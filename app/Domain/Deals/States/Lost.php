<?php

namespace App\Domain\Deals\States;

class Lost extends DealState
{
    public function label(): string
    {
        return 'Lost';
    }
}
