<?php

namespace Database\Factories;

use App\Models\SalesLeadTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesLeadTarget>
 */
class SalesLeadTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_user_id' => User::factory(),
            'period_year' => now()->year,
            'period_month' => now()->month,
            'lead_target' => fake()->numberBetween(1, 100),
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
