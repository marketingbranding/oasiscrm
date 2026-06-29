<?php

use App\Models\CampaignOption;
use App\Models\PlatformOption;
use App\Models\StatusLeadOption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_options', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('campaign_options', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('status_lead_options', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $platforms = ['Kosong', 'Iklan Facebook', 'Iklan Instagram', 'Whatsapp', 'Tiktok'];
        foreach ($platforms as $name) {
            PlatformOption::create(compact('name'));
        }

        $statuses = ['No Respon', 'Diskusi', 'UTJ', 'Tidak Lolos BI Checking', 'Akad'];
        foreach ($statuses as $name) {
            StatusLeadOption::create(compact('name'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('status_lead_options');
        Schema::dropIfExists('campaign_options');
        Schema::dropIfExists('platform_options');
    }
};
