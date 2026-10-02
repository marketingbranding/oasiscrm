<?php

namespace Database\Factories;

use App\Models\ConsumerApplication;
use App\Models\ConsumerIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsumerIssue>
 */
class ConsumerIssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['consumer_application_id' => ConsumerApplication::factory(), 'process_key' => 'proses_bank', 'description' => fake()->sentence(), 'opened_at' => now(), 'status' => 'open'];
    }
}
