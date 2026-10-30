<?php

namespace Database\Seeders;

use App\Domain\Deals\States\Lead;
use App\Domain\Deals\States\Proposal;
use App\Domain\Deals\States\Won;
use App\Domain\Tenant\TenantContext;
use App\Models\Deal;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * 15.6: deterministic deals for the Playwright kanban specs. Runs after DevTenantSeeder
 * (e2e:prepare only; never part of the normal dev seed).
 */
class E2eDealSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        TenantContext::set($tenant);

        $rows = [
            ['name' => 'E2E Move Deal', 'amount' => 1000, 'state' => Lead::class],
            ['name' => 'E2E Stale Deal', 'amount' => 2000, 'state' => Lead::class],
            ['name' => 'E2E Proposal Deal', 'amount' => 3000, 'state' => Proposal::class],
            ['name' => 'E2E Won Deal', 'amount' => 4000, 'state' => Won::class],
        ];

        foreach ($rows as $row) {
            Deal::create($row + ['board_id' => 1]);
        }

        TenantContext::clear();

        $this->command?->info('E2E deals seeded: '.count($rows));
    }
}