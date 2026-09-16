<?php

namespace App\Domain\Deals\States;

class Won extends DealState
{
    public function label(): string
    {
        return 'Won';
    }
}
