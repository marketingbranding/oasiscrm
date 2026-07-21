<?php

namespace App\Services;

use App\Models\FeedbackReport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeedbackDiscordService
{
    public function send(FeedbackReport $report): void
    {
        if (! config('services.feedback_discord.enabled') || blank(config('services.feedback_discord.webhook_url'))) {
            return;
        }

        $fields = [
            ['name' => 'Report ID', 'value' => '#'.$report->id, 'inline' => true],
            ['name' => 'Jenis', 'value' => $report->typeLabel(), 'inline' => true],
            ['name' => 'Modul', 'value' => $report->module ?: '-', 'inline' => true],
            ['name' => 'Cabang', 'value' => $report->branch?->name ?: '-', 'inline' => true],
            ['name' => 'Pelapor', 'value' => $report->creator?->name ?: 'Pengguna nonaktif', 'inline' => true],
            ['name' => 'Halaman', 'value' => $report->route_name ?: '-', 'inline' => true],
            ['name' => 'Judul', 'value' => str($report->title)->limit(200)->toString()],
            ['name' => 'Ringkasan', 'value' => str($report->description)->limit(500)->toString()],
            ['name' => 'Admin URL', 'value' => route('feedback-reports.show', $report)],
        ];
        if (config('services.feedback_discord.include_user_email') && $report->creator?->email) {
            $fields[] = ['name' => 'Email', 'value' => $report->creator->email, 'inline' => true];
        }

        try {
            $response = Http::timeout(10)->post((string) config('services.feedback_discord.webhook_url'), [
                'embeds' => [[
                    'title' => 'Laporan Oasis CRM',
                    'color' => 12663090,
                    'fields' => $fields,
                    'timestamp' => $report->created_at?->toIso8601String(),
                ]],
            ]);
            if (! $response->successful()) {
                Log::warning('Feedback Discord delivery failed', [
                    'operation' => 'feedback_discord_send',
                    'report_id' => $report->id,
                    'status_code' => $response->status(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Feedback Discord delivery failed', [
                'operation' => 'feedback_discord_send',
                'report_id' => $report->id,
                'error_class' => $exception::class,
            ]);
        }
    }
}
