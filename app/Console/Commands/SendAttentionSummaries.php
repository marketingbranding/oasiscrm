<?php

namespace App\Console\Commands;

use App\Services\AttentionSummaryNotificationService;
use Illuminate\Console\Command;

class SendAttentionSummaries extends Command
{
    protected $signature = 'oasis:attention-summaries';

    protected $description = 'Mengirim ringkasan harian penanda perhatian kepada Koordinator.';

    public function __construct(private readonly AttentionSummaryNotificationService $summaries)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sent = $this->summaries->sendForDate(now()->toImmutable());
        $this->info("{$sent} ringkasan dikirim.");

        return self::SUCCESS;
    }
}
