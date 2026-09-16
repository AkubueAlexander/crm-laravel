<?php

namespace App\Domain\Deals\States;

class Qualified extends DealState
{
    public function label(): string
    {
        return 'Qualified';
    }
}
