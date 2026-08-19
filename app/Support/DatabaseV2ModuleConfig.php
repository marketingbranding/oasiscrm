<?php

namespace App\Support;

use App\Models\DatabaseV2\Akad;
use App\Models\DatabaseV2\Bast;
use App\Models\DatabaseV2\BiChecking;
use App\Models\DatabaseV2\DataKonsumen;
use App\Models\DatabaseV2\Pemberkasan;
use App\Models\DatabaseV2\PpjbDev;
use App\Models\DatabaseV2\ProsesBank;
use App\Models\DatabaseV2\Psjb;

class DatabaseV2ModuleConfig
{
    public const LEGACY_IGNORED = [
        'id_kons', 'id_psjb', 'id_berkas', 'id_ppjb_dev', 'no_ppjb_akad', 'no_bast',
        'proses_terakhir', 'umur', 'status_terakhir', 'status_kelengkapan',
        'oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by',
    ];

    public const MODULES = [
        'data_konsumen' => [
            'label' => 'Data Konsumen',
            'model' => DataKonsumen::class,
            'fields' => ['id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_lahir', 'pekerjaan', 'detail_pekerjaan', 'alamat', 'kelurahan', 'kecamatan', 'kabupaten_kota', 'no_hp', 'nama_kondar', 'no_hp_kondar', 'status_cash', 'status_konsumen', 'keterangan'],
            'table' => ['id_kavling', 'nama_konsumen', 'no_hp', 'pekerjaan', 'status_konsumen'],
            'full_width' => ['alamat', 'keterangan'],
            'money' => [],
            'date' => ['tanggal_lahir'],
            'integer' => [],
            'select' => [],
            'ignored_import_headers' => ['id_kons', 'umur', 'proses_terakhir', 'status_terakhir', 'status_kelengkapan'],
        ],
        'bi_checking' => [
            'label' => 'BI Checking',
            'model' => BiChecking::class,
            'fields' => ['id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_slik', 'hasil_slik', 'keterangan'],
            'table' => ['id_kavling', 'nama_konsumen', 'tanggal_slik', 'hasil_slik'],
            'full_width' => ['keterangan'],
            'money' => [],
            'date' => ['tanggal_slik'],
            'integer' => [],
            'select' => [],
            'ignored_import_headers' => ['id_kons', 'id_bi'],
        ],
        'psjb' => [
            'label' => 'PSJB',
            'model' => Psjb::class,
            'fields' => ['id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_psjb', 'nama_koordinator', 'nama_sales', 'harga_unit', 'tanggal_utj', 'utj', 'tanggal_dp_klt', 'dp_all_in', 'nominal_cicilan', 'jumlah_cicilan', 'luas_klt', 'harga_klt_m', 'harga_klt_total', 'cara_pembayaran', 'nama_promo'],
            'table' => ['id_kavling', 'nama_konsumen', 'tanggal_psjb', 'nama_sales', 'harga_unit', 'utj', 'dp_all_in'],
            'full_width' => [],
            'money' => ['harga_unit', 'utj', 'dp_all_in', 'nominal_cicilan', 'harga_klt_total'],
            'date' => ['tanggal_psjb', 'tanggal_utj', 'tanggal_dp_klt'],
            'integer' => ['jumlah_cicilan'],
            'select' => [],
            'ignored_import_headers' => ['id_kons', 'id_psjb'],
        ],
        'pemberkasan' => [
            'label' => 'Pemberkasan',
            'model' => Pemberkasan::class,
            'fields' => ['id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_terima_bank', 'bank', 'kc_unit', 'request_plafond', 'request_tenor', 'tipe_pemberkasan'],
            'table' => ['id_kavling', 'nama_konsumen', 'tanggal_terima_bank', 'bank', 'request_plafond', 'request_tenor'],
            'full_width' => [],
            'money' => ['request_plafond'],
            'date' => ['tanggal_terima_bank'],
            'integer' => ['request_tenor'],
            'select' => [],
            'ignored_import_headers' => ['id_kons', 'id_psjb', 'id_berkas'],
        ],
        'proses_bank' => [
            'label' => 'Proses Bank',
            'model' => ProsesBank::class,
            'fields' => ['id_kavling', 'no_ktp', 'nama_konsumen', 'no_sp3k', 'jenis_respon', 'approved_plafond', 'approved_tenor', 'kategori_revisi', 'detail_revisi', 'kendala'],
            'table' => ['id_kavling', 'nama_konsumen', 'no_sp3k', 'jenis_respon', 'approved_plafond', 'approved_tenor'],
            'full_width' => ['detail_revisi', 'kendala'],
            'money' => ['approved_plafond'],
            'date' => [],
            'integer' => ['approved_tenor'],
            'select' => [],
            'ignored_import_headers' => ['id_kons', 'id_berkas'],
        ],
        'ppjb_dev' => [
            'label' => 'PPJB Developer',
            'model' => PpjbDev::class,
            'fields' => ['id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_sp3k', 'tanggal_ttd_ppjb'],
            'table' => ['id_kavling', 'nama_konsumen', 'tanggal_sp3k', 'tanggal_ttd_ppjb'],
            'full_width' => [],
            'money' => [],
            'date' => ['tanggal_sp3k', 'tanggal_ttd_ppjb'],
            'integer' => [],
            'select' => [],
            'ignored_import_headers' => ['id_kons', 'id_ppjb_dev'],
        ],
        'akad' => [
            'label' => 'Akad',
            'model' => Akad::class,
            'fields' => ['id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_akad', 'kualitas_akad', 'status_bangunan', 'status_dp_konsumen', 'status_utilitas', 'status_konsumen', 'keterangan_terlambat'],
            'table' => ['id_kavling', 'nama_konsumen', 'tanggal_akad', 'kualitas_akad', 'status_bangunan', 'status_dp_konsumen', 'status_utilitas'],
            'full_width' => ['keterangan_terlambat'],
            'money' => [],
            'date' => ['tanggal_akad'],
            'integer' => [],
            'select' => [],
            'ignored_import_headers' => ['id_kons', 'id_ppjb_dev', 'no_ppjb_akad'],
        ],
        'bast' => [
            'label' => 'BAST',
            'model' => Bast::class,
            'fields' => ['id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_bast'],
            'table' => ['id_kavling', 'nama_konsumen', 'tanggal_bast'],
            'full_width' => [],
            'money' => [],
            'date' => ['tanggal_bast'],
            'integer' => [],
            'select' => [],
            'ignored_import_headers' => ['id_kons', 'no_ppjb_akad', 'no_bast'],
        ],
    ];

    public static function labels(): array
    {
        return [
            'id_kavling' => 'ID Kavling',
            'no_ktp' => 'NIK',
            'nama_konsumen' => 'Nama Konsumen',
            'tanggal_lahir' => 'Tanggal Lahir',
            'pekerjaan' => 'Pekerjaan',
            'detail_pekerjaan' => 'Detail Pekerjaan',
            'alamat' => 'Alamat',
            'kelurahan' => 'Kelurahan',
            'kecamatan' => 'Kecamatan',
            'kabupaten_kota' => 'Kabupaten/Kota',
            'no_hp' => 'No. HP',
            'nama_kondar' => 'Nama Kontak Darurat',
            'no_hp_kondar' => 'No. HP Kontak Darurat',
            'status_cash' => 'Status Cash',
            'status_konsumen' => 'Status Konsumen',
            'keterangan' => 'Keterangan',
            'tanggal_slik' => 'Tanggal SLIK',
            'hasil_slik' => 'Hasil SLIK',
            'tanggal_psjb' => 'Tanggal PSJB',
            'nama_koordinator' => 'Nama Koordinator',
            'nama_sales' => 'Nama Sales',
            'harga_unit' => 'Harga Unit',
            'tanggal_utj' => 'Tanggal UTJ',
            'utj' => 'UTJ',
            'tanggal_dp_klt' => 'Tanggal DP KLT',
            'dp_all_in' => 'DP All In',
            'nominal_cicilan' => 'Nominal Cicilan',
            'jumlah_cicilan' => 'Jumlah Cicilan',
            'luas_klt' => 'Luas KLT',
            'harga_klt_m' => 'Harga KLT/m',
            'harga_klt_total' => 'Harga KLT Total',
            'cara_pembayaran' => 'Cara Pembayaran',
            'nama_promo' => 'Nama Promo',
            'tanggal_terima_bank' => 'Tanggal Terima Bank',
            'bank' => 'Bank',
            'kc_unit' => 'KC/Unit',
            'request_plafond' => 'Request Plafond',
            'request_tenor' => 'Request Tenor',
            'tipe_pemberkasan' => 'Tipe Pemberkasan',
            'no_sp3k' => 'No. SP3K',
            'jenis_respon' => 'Jenis Respon',
            'approved_plafond' => 'Approved Plafond',
            'approved_tenor' => 'Approved Tenor',
            'kategori_revisi' => 'Kategori Revisi',
            'detail_revisi' => 'Detail Revisi',
            'kendala' => 'Kendala',
            'tanggal_sp3k' => 'Tanggal SP3K',
            'tanggal_ttd_ppjb' => 'Tanggal TTD PPJB',
            'tanggal_akad' => 'Tanggal Akad',
            'kualitas_akad' => 'Kualitas Akad',
            'status_bangunan' => 'Status Bangunan',
            'status_dp_konsumen' => 'Status DP Konsumen',
            'status_utilitas' => 'Status Utilitas',
            'keterangan_terlambat' => 'Keterangan Terlambat',
            'tanggal_bast' => 'Tanggal BAST',
        ];
    }

    public static function config(string $module): ?array
    {
        return self::MODULES[$module] ?? null;
    }
}
