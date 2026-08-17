@extends('layouts.crm')
@section('title', 'Preview Import Proses Historis - Oasis CRM')
@section('content')
@php $processType = $batch->rows->first()->normalized_data['process_type'] ?? null; $processLabel = \App\Services\ConsumerHistoricalProcessImportService::PROCESS_TYPES[$processType] ?? $processType; @endphp
<x-crm.page-header variant="canonical" eyebrow="Konsumen Progress" title="Preview Import Proses Historis" description="Tinjau hasil mapping sebelum proses historis disimpan ke data lokal." />
<div class="mb-4 grid grid-cols-2 gap-0 border-2 border-black bg-white text-center text-xs font-bold sm:grid-cols-5">
    @foreach(['Total' => $batch->total_rows, 'Siap' => $batch->ready_rows, 'Sudah Ada' => $batch->already_imported_rows, 'Review' => $batch->review_rows, 'Tidak Valid' => $batch->invalid_rows] as $label => $value)<div class="border-r border-black p-3">{{ $label }}<br><span class="text-xl">{{ $value }}</span></div>@endforeach
</div>
<div class="mb-4 border-2 border-black bg-white p-4 text-sm"><strong>{{ $batch->branch->name }}</strong> / {{ $batch->project->project_name }}<br>Jenis Proses: <strong>{{ $processLabel }}</strong><br>Sumber: TSV manual. Preview tidak mengubah data lokal.</div>
<div class="overflow-x-auto border-2 border-black bg-white"><table class="min-w-full text-left text-xs"><thead class="bg-black text-white"><tr><th class="p-2">Baris</th><th class="p-2">Status</th><th class="p-2">Kavling</th><th class="p-2">Nama</th><th class="p-2">Tanggal</th><th class="p-2">Status Proses</th><th class="p-2">Catatan</th></tr></thead><tbody>@foreach($batch->rows as $row) <tr class="border-t border-gray-300"><td class="p-2">{{ $row->line_number }}</td><td class="p-2 font-bold">{{ $row->status }}</td><td class="p-2">{{ $row->normalized_data['kavling'] ?? '' }}</td><td class="p-2">{{ $row->normalized_data['name'] ?? '—' }}</td><td class="p-2">{{ $row->normalized_data['date'] ?? '—' }}</td><td class="p-2">{{ $row->normalized_data['status'] ?? ($row->normalized_data['bank_status'] ?? '—') }}</td><td class="p-2">{{ $row->skip_reason ? 'Dilewati: sebelumnya '.$row->skip_reason.'. ' : '' }}{{ implode(' ', array_merge($row->warnings ?? [], $row->errors ?? [])) }}</td></tr>@endforeach</tbody></table></div>
@if($batch->status === 'preview_ready')
    <form method="POST" action="{{ route('historical-process-import.confirm', $batch) }}" class="mt-4 flex gap-2">@csrf<input type="hidden" name="expected_updated_at" value="{{ $batch->updated_at->toISOString() }}"><x-crm.button type="submit" variant="primary" accent="sales">Import Data Siap</x-crm.button><x-crm.button href="{{ route('historical-process-import.create') }}" variant="secondary">Batal</x-crm.button></form>
@else
    <div class="mt-4 border-2 border-black bg-[#d8f3dc] p-4 font-bold">Import selesai. Data proses historis telah tersimpan.</div>
@endif
@endsection
