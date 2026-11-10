<?php

namespace Database\Seeders;

use App\Domain\Tenant\TenantContext;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Seeder;


class E2eProductSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        TenantContext::set($tenant);

        (new Product())->forceFill([
            'tenant_id' => $tenant->id,
            'sku' => 'E2E-WIDGET',
            'name' => 'E2E Widget',
            'unit_price' => '100.00',
            'tax_category' => 'standard',
            'is_active' => true,
        ])->save();

        TenantContext::clear();

        $this->command?->info('E2E product seeded.');
    }
}
