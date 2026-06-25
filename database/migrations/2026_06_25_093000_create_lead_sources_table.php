<?php

use App\Models\LeadSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $defaults = ['Event Mandiri', 'Kerjasama Event', 'Event Internal', 'Pameran', 'Digital Campaign', 'Walk-in', 'Referensi', 'Media Sosial', 'Telemarketing', 'Website'];
        foreach ($defaults as $name) {
            LeadSource::create(compact('name'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
