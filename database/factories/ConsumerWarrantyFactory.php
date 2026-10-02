<?php

namespace Database\Factories;

use App\Models\ConsumerApplication;
use App\Models\ConsumerWarranty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsumerWarranty>
 */
class ConsumerWarrantyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['consumer_application_id' => ConsumerApplication::factory(), 'status_komplain' => 'Belum Dipilih', 'status_garansi' => 'Belum Dipilih'];
    }
}
