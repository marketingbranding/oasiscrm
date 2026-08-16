<?php

namespace Database\Factories;

use App\Models\ConsumerDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConsumerDocument> */
class ConsumerDocumentFactory extends Factory
{
    protected $model = ConsumerDocument::class;

    public function definition(): array
    {
        return ['consumer_application_id' => ConsumerApplicationFactory::new(), 'document_type' => 'ktp', 'status' => 'pending'];
    }
}
