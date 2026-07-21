@extends('layouts.crm')
@section('title', 'Review Laporan - Oasis CRM')
@section('content')
<div class="bg-[#c0392b] text-white border-2 border-black px-4 py-2 mb-5"><h1 class="font-['Arial_Black'] font-black text-xl uppercase">Review Laporan</h1></div>
<form method="GET" class="border-2 border-black bg-white p-3 mb-4 flex flex-wrap gap-2">
    <select name="type" class="border-2 border-black px-2 py-1 bg-white"><option value="">Semua Jenis</option>@foreach(\App\Models\FeedbackReport::TYPES as $type)<option value="{{ $type }}" @selected(request('type') === $type)>{{ str_replace('_', ' ', ucfirst($type)) }}</option>@endforeach</select>
    <select name="status" class="border-2 border-black px-2 py-1 bg-white"><option value="">Semua Status</option>@foreach(\App\Models\FeedbackReport::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select>
    <select name="branch_id" class="border-2 border-black px-2 py-1 bg-white"><option value="">Semua Cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string)request('branch_id') === (string)$branch->id)>{{ $branch->name }}</option>@endforeach</select>
    <select name="module" class="border-2 border-black px-2 py-1 bg-white"><option value="">Semua Modul</option>@foreach($modules as $module)<option value="{{ $module }}" @selected(request('module') === $module)>{{ $module }}</option>@endforeach</select>
    <input name="search" value="{{ request('search') }}" placeholder="Cari judul atau deskripsi" class="border-2 border-black px-2 py-1 grow min-w-48">
    <button class="border-2 border-black bg-black text-white px-4 py-1 font-bold">Filter</button>
</form>
<div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>#</th><th>Jenis</th><th>Judul</th><th>Modul</th><th>Cabang</th><th>Status</th><th>Prioritas</th><th>Pelapor</th><th>Tanggal</th><th>Aksi</th></tr></thead><tbody>
@forelse($reports as $report)<tr><td>{{ $reports->firstItem() + $loop->index }}</td><td>{{ $report->typeLabel() }}</td><td title="{{ $report->title }}">{{ Str::limit($report->title, 40) }}</td><td>{{ $report->module }}</td><td>{{ $report->branch?->name }}</td><td>{{ $report->statusLabel() }}</td><td>{{ ucfirst($report->priority) }}</td><td>{{ $report->creator?->name ?? '-' }}</td><td>{{ $report->created_at->format('d M Y H:i') }}</td><td><a href="{{ route('feedback-reports.show', $report) }}" class="font-bold underline text-[#0000ee]">Buka</a></td></tr>
@empty<tr><td colspan="10" class="text-center">Belum ada laporan.</td></tr>@endforelse
</tbody></table></div><div class="mt-4">{{ $reports->links() }}</div>
@endsection
