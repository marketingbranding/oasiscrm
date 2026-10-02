<?php

namespace Database\Factories;

use App\Models\ConsumerApplication;
use App\Models\ConsumerProcessApplicability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsumerProcessApplicability>
 */
class ConsumerProcessApplicabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['consumer_application_id' => ConsumerApplication::factory(), 'process_key' => 'proses_bank', 'applicability' => 'required'];
    }
}
