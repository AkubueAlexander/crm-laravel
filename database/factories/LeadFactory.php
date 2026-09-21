<?php

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lead> */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'company' => fake()->company(),
            'source' => fake()->randomElement(['web', 'referral', 'event', 'cold-outreach']),
            'status' => 'new',
            'owner_id' => null,
            'converted_at' => null,
            'converted_contact_id' => null,
            'converted_account_id' => null,
            'converted_deal_id' => null,
        ];
    }

    public function converted(): static
    {
        return $this->state(fn () => [
            'status' => 'converted',
            'converted_at' => now(),
        ]);
    }
}