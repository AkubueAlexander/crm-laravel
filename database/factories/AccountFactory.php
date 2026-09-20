<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        // tenant_id is intentionally absent: BelongsToTenant stamps it from TenantContext.
        return [
            'name' => fake()->unique()->company(),
            'industry' => fake()->randomElement(['Software', 'Retail', 'Logistics', 'Finance', 'Health']),
            'website' => fake()->url(),
            'phone' => fake()->phoneNumber(),
            'owner_id' => null,
        ];
    }
}