@extends('layouts.crm')

@section('content')
    <x-crm.page-header eyebrow="Pemantauan" title="Riwayat Tim" description="Linimasa perubahan Lead dan agenda bawahan dalam scope organisasi." />
    <x-crm.card>
        <form method="GET" class="grid gap-3 md:grid-cols-4">
            <label class="crm-field"><span>Bawahan langsung</span><select name="user_id" class="crm-control"><option value="">Semua</option>@foreach($directUsers as $user)<option value="{{ $user->id }}" @selected(($filters['user_id'] ?? null) == $user->id)>{{ $user->name }}</option>@endforeach</select></label>
            <label class="crm-field"><span>Dari</span><input class="crm-control" type="date" name="date_from" value="{{ $filters['date_from'] ?? $from->toDateString() }}"></label>
            <label class="crm-field"><span>Sampai</span><input class="crm-control" type="date" name="date_to" value="{{ $filters['date_to'] ?? $to->toDateString() }}"></label>
            <label class="crm-field"><span>Jenis</span><select name="type" class="crm-control"><option value="">Semua</option>@foreach(['lead_stage' => 'Tahap Lead', 'lead_status' => 'Status Lead', 'lead_transfer' => 'Alih Lead', 'lead_note' => 'Catatan Lead', 'agenda' => 'Rencana Agenda', 'agenda_report' => 'Laporan Agenda'] as $value => $label)<option value="{{ $value }}" @selected(($filters['type'] ?? null) === $value)>{{ $label }}</option>@endforeach</select></label>
            <button class="crm-button crm-button--primary md:col-span-4">Terapkan</button>
        </form>
    </x-crm.card>
    <x-crm.card title="Linimasa">
        <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Waktu</th><th>Jenis</th><th>Ringkasan</th><th>Pemilik</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ \Carbon\CarbonImmutable::parse($row->happened_at)->format('d/m/Y H:i') }}</td><td>{{ $row->type }}</td><td>{{ $row->summary }}</td><td>{{ $row->owner_name }}</td></tr>@empty<tr><td colspan="4">Belum ada riwayat pada filter ini.</td></tr>@endforelse</tbody></table></div>{{ $rows->links() }}
    </x-crm.card>
@endsection
