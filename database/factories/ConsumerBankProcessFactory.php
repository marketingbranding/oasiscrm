<?php

namespace Database\Factories;

use App\Models\ConsumerBankProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConsumerBankProcess> */
class ConsumerBankProcessFactory extends Factory
{
    protected $model = ConsumerBankProcess::class;

    public function definition(): array
    {
        return ['consumer_application_id' => ConsumerApplicationFactory::new(), 'status' => 'pending'];
    }
}
