<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Kontrak canonical aplikasi konsumen V1';

    public function up(): void
    {
        Schema::table('consumer_applications', function (Blueprint $table) {
            $table->string('id_transaksi', 80)->nullable()->after('id');
        });

        $applications = DB::table('consumer_applications')
            ->select(['id', 'branch_id'])
            ->whereNull('id_transaksi')
            ->orderBy('id')
            ->get();

        foreach ($applications as $application) {
            $branchCode = DB::table('branches')->where('id', $application->branch_id)->value('code');
            $branchToken = $this->branchToken($branchCode, (int) $application->branch_id);

            DB::table('consumer_applications')
                ->where('id', $application->id)
                ->update(['id_transaksi' => 'TRX-'.$branchToken.'-'.$application->id]);
        }

        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->string('id_transaksi', 80)->nullable(false)->change();
            $table->unique('id_transaksi', 'consumer_applications_id_transaksi_unique');
        });

        Schema::table('consumer_stage_events', function (Blueprint $table): void {
            $table->string('decision', 40)->nullable()->after('status');
        });

        Schema::table('consumer_bank_processes', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_no')->nullable()->after('id');
            $table->string('attempt_key', 120)->nullable()->after('attempt_no');
        });

        $transactionIds = DB::table('consumer_applications')->pluck('id_transaksi', 'id');
        $nextAttemptNumbers = [];
        $bankProcesses = DB::table('consumer_bank_processes')
            ->select(['id', 'consumer_application_id'])
            ->orderBy('consumer_application_id')
            ->orderBy('id')
            ->get();

        foreach ($bankProcesses as $bankProcess) {
            $applicationId = (int) $bankProcess->consumer_application_id;
            $attemptNo = ($nextAttemptNumbers[$applicationId] ?? 0) + 1;
            $nextAttemptNumbers[$applicationId] = $attemptNo;
            $transactionId = $transactionIds[$applicationId] ?? 'TRX-APP-'.$applicationId;

            DB::table('consumer_bank_processes')
                ->where('id', $bankProcess->id)
                ->update([
                    'attempt_no' => $attemptNo,
                    'attempt_key' => $transactionId.'-BANK-'.str_pad((string) $attemptNo, 3, '0', STR_PAD_LEFT),
                ]);
        }

        Schema::table('consumer_bank_processes', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_no')->nullable(false)->change();
            $table->string('attempt_key', 120)->nullable(false)->change();
            $table->unique(['consumer_application_id', 'attempt_no'], 'consumer_bank_processes_application_attempt_unique');
            $table->unique('attempt_key', 'consumer_bank_processes_attempt_key_unique');
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'description' => 'Menetapkan ID transaksi konsumen yang immutable, histori SLIK append-only, identitas bank attempt, dan fact Ready100 berbasis histori stage tanpa mengubah kompatibilitas legacy.',
                'category' => 'added',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', self::CHANGELOG_TITLE)
            ->delete();

        Schema::table('consumer_bank_processes', function (Blueprint $table): void {
            $table->dropUnique('consumer_bank_processes_application_attempt_unique');
            $table->dropUnique('consumer_bank_processes_attempt_key_unique');
            $table->dropColumn(['attempt_no', 'attempt_key']);
        });

        Schema::table('consumer_stage_events', function (Blueprint $table): void {
            $table->dropColumn('decision');
        });

        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->dropUnique('consumer_applications_id_transaksi_unique');
            $table->dropColumn('id_transaksi');
        });
    }

    private function branchToken(?string $branchCode, int $branchId): string
    {
        $token = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $branchCode), '-'));

        return $token !== '' ? $token : 'BRANCH'.$branchId;
    }
};
