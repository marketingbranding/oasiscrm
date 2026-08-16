<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\Customer;
use App\Models\LeadMaster;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConsumerApplication> */
class ConsumerApplicationFactory extends Factory
{
    protected $model = ConsumerApplication::class;

    public function definition(): array
    {
        $branch = Branch::create(['name' => fake()->city(), 'code' => Str::upper(Str::random(8)), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => fake()->company(), 'is_active' => true]);

        return [
            'customer_id' => Customer::factory(),
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'application_status' => 'draft',
        ];
    }
}
