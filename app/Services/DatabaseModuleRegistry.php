<?php

namespace App\Services;

use InvalidArgumentException;

final class DatabaseModuleRegistry
{
    public function all(): array
    {
        $column = fn (string $key, string $label, string $path, string $classification, string $type = 'text', bool $sortable = false, bool $filterable = false, array $edit = []): array => array_merge(compact('key', 'label', 'path', 'type', 'sortable', 'filterable', 'classification'), [
            'data_type' => $type,
            'editable' => false,
            'edit_type' => null,
            'validation' => [],
            'write_strategy' => null,
            'permission' => 'consumer_progress.manage',
            'scope_action' => 'manage',
            'readonly_reason' => match ($classification) {
                'relation_lifecycle' => 'Relasi dan lifecycle dikelola melalui workflow terkait.',
                'process_stage' => 'Tahap proses dikelola melalui workflow terkait.',
                'derived_identifier' => 'Nilai turunan atau identifier dihitung sistem.',
                default => 'Kolom ini hanya dapat dibaca.',
            },
        ], $edit);
        $editable = fn (string $strategy, string $target, string $field, string $editType = 'text', array $validation = [], array $extra = []): array => [
            'editable' => true,
            'edit_type' => $editType,
            'validation' => $validation,
            'write_strategy' => $strategy,
            'permission' => 'consumer_progress.manage',
            'readonly_reason' => null,
            'write_target' => ['model' => $target, 'field' => $field],
        ] + $extra;
        $application = fn (string $key, string $label, string $path, string $classification, string $type = 'text', bool $sortable = false, bool $filterable = false, array $edit = []): array => $column($key, $label, $path, $classification, $type, $sortable, $filterable, $edit);
        $process = fn (string $key, string $label, string $path, string $type = 'text', bool $sortable = false, bool $filterable = false, string $classification = 'process_stage'): array => $column($key, $label, $path, $classification, $type, $sortable, $filterable, ['readonly_reason' => 'Data proses dan lifecycle hanya dapat dibaca dari modul ini.']);
        $identity = [
            $process('customer_name', 'Nama Konsumen', 'application.customer.name', sortable: true, classification: 'simple_master'),
            $process('project', 'Proyek', 'application.project.project_name', sortable: true, classification: 'relation_lifecycle'),
            $process('kavling', 'Kavling', 'application.kavling.kavling_code', classification: 'relation_lifecycle'),
        ];

        return [
            'data-konsumen' => $this->module('data-konsumen', 'Data Konsumen', 'Data utama ConsumerApplication lokal.', null, null, [
                $application('customer_name', 'Nama Konsumen', 'record.customer.name', 'simple_master', sortable: true, edit: $editable('customer_field', 'customer', 'name', validation: ['required', 'string', 'max:255'])),
                $application('phone', 'No HP', 'record.customer.phone', 'simple_master', edit: $editable('customer_field', 'customer', 'phone', validation: ['required', 'string', 'max:50'])),
                $application('project', 'Proyek', 'record.project.project_name', 'relation_lifecycle'),
                $application('kavling', 'Kavling', 'record.kavling.kavling_code', 'relation_lifecycle'),
                $application('sales', 'Sales', 'record.sales.name', 'relation_lifecycle'),
                $application('consumer_status', 'Status Konsumen', 'record.consumer_status', 'process_stage', filterable: true),
                $application('current_stage', 'Current Stage / Proses Terakhir', 'record.current_stage', 'derived_identifier', filterable: true),
                $application('completeness', 'Kelengkapan Data', 'record.source_completeness_status', 'derived_identifier', filterable: true),
                $application('notes', 'Keterangan', 'record.notes', 'simple_application', edit: $editable('application_field', 'application', 'notes', validation: ['nullable', 'string', 'max:5000'])),
                $application('status_cash', 'Status Cash', 'record.status_cash', 'simple_application', edit: $editable('application_field', 'application', 'status_cash', 'select', ['nullable', 'boolean'], ['options' => [['value' => '', 'label' => 'Belum diisi'], ['value' => true, 'label' => 'Ya'], ['value' => false, 'label' => 'Tidak']]])),
            ]),
            'bi-checking' => $this->module('bi-checking', 'BI Checking', 'Riwayat pemeriksaan BI/SLIK konsumen.', 'stageEvents', 'bi_checking', [...$identity, $process('occurred_at', 'Tanggal', 'record.occurred_at', 'date'), $process('status', 'Status', 'record.status', filterable: true), $process('source', 'Sumber', 'record.source'), $process('notes', 'Keterangan', 'record.notes')]),
            'psjb' => $this->module('psjb', 'PSJB', 'Data proses PSJB konsumen.', 'psjbs', 'PSJB', [...$identity, $process('tanggal_psjb', 'Tanggal PSJB', 'record.tanggal_psjb', 'date'), $process('payment_method', 'Cara Pembayaran', 'record.cara_pembayaran', filterable: true), $process('status', 'Status', 'record.status', filterable: true), $process('notes', 'Keterangan', 'record.keterangan')]),
            'pemberkasan' => $this->module('pemberkasan', 'Pemberkasan', 'Data pemberkasan yang diterima bank.', 'bankProcesses', 'pemberkasan', [...$identity, $process('received_at', 'Tanggal Terima', 'record.tanggal_terima_bank', 'date'), $process('bank', 'Bank', 'record.bank_name', filterable: true), $process('type', 'Tipe', 'record.tipe_pemberkasan', filterable: true), $process('status', 'Status', 'record.status', filterable: true), $process('notes', 'Keterangan', 'record.notes')]),
            'proses-bank' => $this->module('proses-bank', 'Proses Bank', 'Data tindak lanjut dan keputusan bank.', 'bankProcesses', 'proses_bank', [...$identity, $process('bank', 'Bank', 'record.bank_name', filterable: true), $process('response', 'Respons', 'record.response_type', filterable: true), $process('status', 'Status', 'record.status', filterable: true), $process('approved_plafond', 'Plafond Disetujui', 'record.approved_plafond', 'money'), $process('approved_tenor', 'Tenor Disetujui', 'record.approved_tenor', 'number'), $process('notes', 'Keterangan', 'record.notes')]),
            'ppjb' => $this->module('ppjb', 'PPJB', 'Data PPJB Developer konsumen.', 'ppjbDevelopers', 'ppjb_dev', [...$identity, $process('sp3k_at', 'Tanggal SP3K', 'record.tanggal_sp3k', 'date'), $process('signed_at', 'Tanggal TTD PPJB', 'record.tanggal_ttd_ppjb', 'date'), $process('notes', 'Keterangan', 'record.notes')]),
            'akad' => $this->module('akad', 'Akad', 'Data pelaksanaan akad konsumen.', 'akadRecords', 'akad', [...$identity, $process('akad_at', 'Tanggal Akad', 'record.tanggal_akad', 'date'), $process('quality', 'Kualitas Akad', 'record.kualitas_akad', filterable: true), $process('building_status', 'Status Bangunan', 'record.status_bangunan', filterable: true), $process('status', 'Status Konsumen', 'record.status_konsumen', filterable: true), $process('notes', 'Keterangan', 'record.keterangan_terlambat')]),
            'bast' => $this->module('bast', 'BAST', 'Data serah terima bangunan konsumen.', 'bastRecords', 'bast', [...$identity, $process('bast_at', 'Tanggal BAST', 'record.tanggal_bast', 'date')]),
        ];
    }

    public function get(string $slug): array
    {
        return $this->all()[$slug] ?? throw new InvalidArgumentException("Module consumer database [{$slug}] tidak dikenal.");
    }

    public function slugs(): array
    {
        return array_keys($this->all());
    }

    public function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private function module(string $key, string $label, string $description, ?string $relation, ?string $stage, array $columns): array
    {
        return compact('key', 'label', 'description', 'relation', 'stage', 'columns') + ['default_columns' => array_column($columns, 'key')];
    }
}
