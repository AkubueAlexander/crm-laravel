<?php

namespace App\Domain\Leads\Exceptions;

use App\Models\Lead;
use Exception;

class LeadAlreadyConvertedException extends Exception
{
    public function __construct(public readonly Lead $lead)
    {
        parent::__construct('This lead has already been converted.');
    }
}