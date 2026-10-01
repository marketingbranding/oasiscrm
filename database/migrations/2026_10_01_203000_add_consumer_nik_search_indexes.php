<?php

use App\Models\ConsumerApplication;
use App\Models\Customer;
use App\Support\ConsumerIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->char('nik_hash', 64)->nullable()->after('nik_encrypted');
            $table->index('nik_hash', 'customers_nik_hash_index');
        });

        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->char('nik_hash', 64)->nullable()->after('nik');
            $table->index('nik_hash', 'consumer_applications_nik_hash_index');
        });

        Customer::query()->select(['id', 'nik_encrypted'])->eachById(function (Customer $customer): void {
            DB::table('customers')->where('id', $customer->id)->update([
                'nik_hash' => ConsumerIdentity::nikHash($customer->nik_encrypted),
            ]);
        });

        ConsumerApplication::query()->select(['id', 'nik'])->eachById(function (ConsumerApplication $application): void {
            DB::table('consumer_applications')->where('id', $application->id)->update([
                'nik_hash' => ConsumerIdentity::nikHash($application->nik),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->dropIndex('consumer_applications_nik_hash_index');
            $table->dropColumn('nik_hash');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_nik_hash_index');
            $table->dropColumn('nik_hash');
        });
    }
};
