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
        $statuses = ['draft', 'review', 'approved', 'posted'];

        foreach ($branches as $branch) {
            $admin = User::where('branch_id', $branch->id)->first()
                ?? User::where('email', 'admin@oasis.com')->first();

            for ($i = 1; $i <= 5; $i++) {
                ContentItem::create([
                    'branch_id' => $branch->id,
                    'title' => "Konten {$branch->name} - Item {$i}",
                    'platform' => $platforms[array_rand($platforms)],
                    'scheduled_date' => now()->addDays(rand(1, 30)),
                    'status' => $statuses[array_rand($statuses)],
                    'notes' => "Catatan untuk konten {$branch->name} item {$i}",
                    'created_by' => $admin->id,
                ]);
            }
        }
    }
}
