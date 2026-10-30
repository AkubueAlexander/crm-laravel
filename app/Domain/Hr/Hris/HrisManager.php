<?php

namespace App\Domain\Hr\Hris;

use InvalidArgumentException;

/**
 * 13.3: resolves the configured HRIS adapter (config 'services.hris.driver', default 'null').
 * To add a provider: implement HrisAdapter, add a case below, set the driver in config.
 */
class HrisManager
{
    public function adapter(): HrisAdapter
    {
        $driver = (string) (config('services.hris.driver') ?: 'null');

        return match ($driver) {
            'null' => new NullHrisAdapter(),
            default => throw new InvalidArgumentException("Unknown HRIS driver [{$driver}]."),
        };
    }
}
