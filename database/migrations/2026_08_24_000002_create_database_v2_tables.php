<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('id_kavling', 50)->nullable();
            $table->string('no_ktp', 50)->nullable();
            $table->string('nama_konsumen', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'no_ktp']);
            $table->index(['branch_id', 'id_kavling']);
        };

        Schema::create('db_v2_data_konsumen', function (Blueprint $table) use ($common) {
            $common($table);
            $table->date('tanggal_lahir')->nullable();
            $table->string('pekerjaan')->nullable();
            $table->string('detail_pekerjaan')->nullable();
            $table->text('alamat')->nullable();
            $table->string('kelurahan')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kabupaten_kota')->nullable();
            $table->string('no_hp', 50)->nullable();
            $table->string('nama_kondar')->nullable();
            $table->string('no_hp_kondar', 50)->nullable();
            $table->string('status_cash')->nullable();
            $table->string('status_konsumen')->nullable();
            $table->text('keterangan')->nullable();
        });

        Schema::create('db_v2_bi_checking', function (Blueprint $table) use ($common) {
            $common($table);
            $table->date('tanggal_slik')->nullable();
            $table->string('hasil_slik')->nullable();
            $table->text('keterangan')->nullable();
        });

        Schema::create('db_v2_psjb', function (Blueprint $table) use ($common) {
            $common($table);
            $table->date('tanggal_psjb')->nullable();
            $table->string('nama_koordinator')->nullable();
            $table->string('nama_sales')->nullable();
            $table->decimal('harga_unit', 15, 2)->nullable();
            $table->date('tanggal_utj')->nullable();
            $table->decimal('utj', 15, 2)->nullable();
            $table->date('tanggal_dp_klt')->nullable();
            $table->decimal('dp_all_in', 15, 2)->nullable();
            $table->decimal('nominal_cicilan', 15, 2)->nullable();
            $table->integer('jumlah_cicilan')->nullable();
            $table->decimal('luas_klt', 10, 2)->nullable();
            $table->decimal('harga_klt_m', 15, 2)->nullable();
            $table->decimal('harga_klt_total', 15, 2)->nullable();
            $table->string('cara_pembayaran')->nullable();
            $table->string('nama_promo')->nullable();
        });

        Schema::create('db_v2_pemberkasan', function (Blueprint $table) use ($common) {
            $common($table);
            $table->date('tanggal_terima_bank')->nullable();
            $table->string('bank')->nullable();
            $table->string('kc_unit')->nullable();
            $table->decimal('request_plafond', 15, 2)->nullable();
            $table->integer('request_tenor')->nullable();
            $table->string('tipe_pemberkasan')->nullable();
        });

        Schema::create('db_v2_proses_bank', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('no_sp3k')->nullable();
            $table->string('jenis_respon')->nullable();
            $table->decimal('approved_plafond', 15, 2)->nullable();
            $table->integer('approved_tenor')->nullable();
            $table->string('kategori_revisi')->nullable();
            $table->text('detail_revisi')->nullable();
            $table->text('kendala')->nullable();
        });

        Schema::create('db_v2_ppjb_dev', function (Blueprint $table) use ($common) {
            $common($table);
            $table->date('tanggal_sp3k')->nullable();
            $table->date('tanggal_ttd_ppjb')->nullable();
        });

        Schema::create('db_v2_akad', function (Blueprint $table) use ($common) {
            $common($table);
            $table->date('tanggal_akad')->nullable();
            $table->string('kualitas_akad')->nullable();
            $table->string('status_bangunan')->nullable();
            $table->string('status_dp_konsumen')->nullable();
            $table->string('status_utilitas')->nullable();
            $table->string('status_konsumen')->nullable();
            $table->text('keterangan_terlambat')->nullable();
        });

        Schema::create('db_v2_bast', function (Blueprint $table) use ($common) {
            $common($table);
            $table->date('tanggal_bast')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_v2_bast');
        Schema::dropIfExists('db_v2_akad');
        Schema::dropIfExists('db_v2_ppjb_dev');
        Schema::dropIfExists('db_v2_proses_bank');
        Schema::dropIfExists('db_v2_pemberkasan');
        Schema::dropIfExists('db_v2_psjb');
        Schema::dropIfExists('db_v2_bi_checking');
        Schema::dropIfExists('db_v2_data_konsumen');
    }
};
