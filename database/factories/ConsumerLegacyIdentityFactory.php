<?php

namespace Database\Factories;

use App\Models\ConsumerLegacyIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConsumerLegacyIdentity> */
class ConsumerLegacyIdentityFactory extends Factory
{
    protected $model = ConsumerLegacyIdentity::class;

    public function definition(): array
    {
        return ['legacy_source' => 'google_progress', 'external_key' => fake()->uuid(), 'mapping_status' => 'unmapped'];
    }
}
