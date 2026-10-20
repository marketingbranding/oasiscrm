<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SalesDailyPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesDailyPlan>
 */
class SalesDailyPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branchId = Branch::query()->value('id') ?? Branch::create([
            'name' => 'Factory Branch',
            'code' => 'FCT'.fake()->unique()->numerify('###'),
            'is_active' => true,
        ])->id;

        return [
            'branch_id' => $branchId,
            'sales_user_id' => User::factory(),
            'plan_date' => today()->nextWeekday(),
            'status' => 'draft',
            'submitted_at' => null,
            'submitted_by' => null,
            'last_modified_by' => null,
            'lock_version' => 0,
        ];
    }
}
