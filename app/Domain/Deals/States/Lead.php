<?php

namespace App\Domain\Deals\States;

class Lead extends DealState
{
    public function label(): string
    {
        return 'Lead';
    }
}
