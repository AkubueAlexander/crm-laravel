<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Deal;
use App\Models\Tenant;

it('scopes deals to the current tenant and returns nothing when no tenant context is set', function () {
    $tenantA = Tenant::factory()->createOne();
    $tenantB = Tenant::factory()->createOne();

    TenantContext::set($tenantA);
    Deal::create(['name' => 'Tenant A Deal']);

    TenantContext::set($tenantB);
    Deal::create(['name' => 'Tenant B Deal']);

    TenantContext::set($tenantA);
    expect(Deal::count())->toBe(1);

    TenantContext::set($tenantB);
    expect(Deal::count())->toBe(1);

    // The core regression: no tenant context resolved -> fail closed, not open.
    TenantContext::clear();
    expect(Deal::count())->toBe(0);

    // withoutTenantScope must still see everything, for admin/console tooling.
    expect(Deal::withoutTenantScope()->count())->toBe(2);
});

it('refuses to save a deal without a resolved tenant context', function () {
    TenantContext::clear();

    expect(fn () => Deal::create(['name' => 'Orphan Deal']))
        ->toThrow(RuntimeException::class);
});
