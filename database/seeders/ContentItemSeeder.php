<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContentItemSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        $platforms = ['Instagram', 'Facebook', 'TikTok', 'Twitter', 'Website'];
        $statuses = ['todo', 'in_progress', 'completed', 'lost_track'];

        foreach ($branches as $branch) {
            $admin = User::where('branch_id', $branch->id)->first()
                ?? User::where('email', 'admin@oasis.com')->first();

            for ($i = 1; $i <= 5; $i++) {
                $deadline = now()->addDays(rand(1, 30));
                $status = $statuses[array_rand($statuses)];

                ContentItem::create([
                    'branch_id' => $branch->id,
                    'title' => "Task {$branch->name} - Item {$i}",
                    'task_detail' => "Detail task {$branch->name} item {$i}",
                    'platform' => $platforms[array_rand($platforms)],
                    'start_date' => now()->addDays(rand(0, 10)),
                    'deadline_date' => $deadline,
                    'scheduled_date' => $deadline,
                    'priority' => collect(['low', 'medium', 'high', 'urgent'])->random(),
                    'status' => $status,
                    'completed_at' => $status === 'completed' ? now() : null,
                    'notes' => "Catatan untuk task {$branch->name} item {$i}",
                    'created_by' => $admin->id,
                ]);
            }
        }
    }
}
