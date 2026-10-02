<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ConsumerNup;
use App\Models\Customer;
use App\Models\LeadMaster;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConsumerNup>
 */
class ConsumerNupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branch = Branch::create(['name' => fake()->city(), 'code' => Str::upper(Str::random(8)), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => fake()->company(), 'is_active' => true]);

        return [
            'customer_id' => Customer::factory(),
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'nup_number' => 'NUP-'.fake()->unique()->numerify('#####'),
            'registered_at' => now()->toDateString(),
            'status' => 'waiting',
        ];
    }
}
