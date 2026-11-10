<?php

namespace Database\Seeders;

use App\Domain\Tenant\TenantContext;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Database\Seeder;


class E2eLeadSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        TenantContext::set($tenant);

        Lead::create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada.lovelace@e2e.test',
            'company' => 'E2E Analytical Ltd',
            'source' => 'e2e',
        ]);

        TenantContext::clear();

        $this->command?->info('E2E lead seeded.');
    }
}
