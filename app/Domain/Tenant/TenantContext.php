<?php

namespace App\Domain\Tenant;

use App\Models\Tenant;


class TenantContext
{
    private static ?Tenant $tenant = null;

    public static function set(Tenant $tenant): void
    {
        self::$tenant = $tenant;
    }

    public static function get(): ?Tenant
    {
        return self::$tenant;
    }

    public static function id(): int|string|null
    {
        return self::$tenant?->id;
    }

    public static function hasTenant(): bool
    {
        return self::$tenant !== null;
    }

    public static function clear(): void
    {
        self::$tenant = null;
    }
}
