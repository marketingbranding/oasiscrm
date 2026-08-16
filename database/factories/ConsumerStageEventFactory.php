<?php

namespace Database\Factories;

use App\Models\ConsumerStageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConsumerStageEvent> */
class ConsumerStageEventFactory extends Factory
{
    protected $model = ConsumerStageEvent::class;

    public function definition(): array
    {
        return ['consumer_application_id' => ConsumerApplicationFactory::new(), 'stage' => 'akad', 'status' => 'completed'];
    }
}
