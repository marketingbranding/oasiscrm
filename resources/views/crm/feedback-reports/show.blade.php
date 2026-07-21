@extends('layouts.crm')
@section('title', 'Detail Laporan - Oasis CRM')
@section('content')
<div class="bg-[#c0392b] text-white border-2 border-black px-4 py-2 mb-5"><h1 class="font-['Arial_Black'] font-black text-xl uppercase">Laporan #{{ $report->id }}</h1></div>
<div class="grid lg:grid-cols-3 gap-4">
<div class="lg:col-span-2 border-2 border-black bg-white p-4 space-y-3">
    <h2 class="font-[Helvetica] font-bold text-lg">{{ $report->title }}</h2>
    <p><strong>Jenis:</strong> {{ $report->typeLabel() }} | <strong>Modul:</strong> {{ $report->module }} | <strong>Cabang:</strong> {{ $report->branch?->name }}</p>
    <p><strong>Pelapor:</strong> {{ $report->creator?->name ?? 'Pengguna nonaktif' }} | <strong>Dikirim:</strong> {{ $report->created_at->format('d M Y H:i') }}</p>
    <div class="border-2 border-black bg-gray-50 p-3 whitespace-pre-line">{{ $report->description }}</div>
    @foreach(['activity' => 'Aktivitas', 'actual_result' => 'Yang Terjadi', 'expected_result' => 'Yang Diharapkan', 'suggestion' => 'Saran / Gambaran Fitur', 'impact' => 'Dampak', 'target_users' => 'Pengguna Fitur', 'additional_notes' => 'Catatan Tambahan'] as $field => $label)
        @if(filled($report->{$field}))<div><strong>{{ $label }}:</strong><div class="whitespace-pre-line">{{ $report->{$field} }}</div></div>@endif
    @endforeach
    @if($report->screenshot_path)<a href="{{ route('feedback-reports.screenshot', $report) }}" class="font-bold underline text-[#0000ee]">Lihat Screenshot</a>@endif
    <div class="border-t-2 border-black pt-3 text-xs space-y-1"><p><strong>Route:</strong> {{ $report->route_name ?: '-' }}</p><p><strong>Halaman:</strong> {{ $report->page_url ?: '-' }}</p><p><strong>Browser / OS:</strong> {{ $report->user_agent_summary ?: '-' }}</p><p><strong>Layar:</strong> {{ $report->screen_size ?: '-' }}</p><p><strong>Versi:</strong> {{ $report->app_version ?: '-' }}</p></div>
</div>
<form method="POST" action="{{ route('feedback-reports.review', $report) }}" class="border-2 border-black bg-white p-4 space-y-3 h-fit">@csrf @method('PATCH')
    <label class="block font-bold text-xs">Status<select name="status" class="mt-1 w-full border-2 border-black px-2 py-1 bg-white">@foreach(\App\Models\FeedbackReport::STATUSES as $status)<option value="{{ $status }}" @selected($report->status === $status)>{{ (new \App\Models\FeedbackReport(['status' => $status]))->statusLabel() }}</option>@endforeach</select></label>
    <label class="block font-bold text-xs">Prioritas<select name="priority" class="mt-1 w-full border-2 border-black px-2 py-1 bg-white">@foreach(\App\Models\FeedbackReport::PRIORITIES as $priority)<option value="{{ $priority }}" @selected($report->priority === $priority)>{{ ucfirst($priority) }}</option>@endforeach</select></label>
    <label class="block font-bold text-xs">Penanggung Jawab<select name="assigned_to" class="mt-1 w-full border-2 border-black px-2 py-1 bg-white"><option value="">Belum ditugaskan</option>@foreach($assignees as $user)<option value="{{ $user->id }}" @selected($report->assigned_to === $user->id)>{{ $user->name }}</option>@endforeach</select></label>
    <label class="block font-bold text-xs">Catatan Admin<textarea name="admin_note" rows="5" maxlength="5000" class="mt-1 w-full border-2 border-black px-2 py-1">{{ old('admin_note', $report->admin_note) }}</textarea></label>
    <button class="w-full border-2 border-black bg-black text-white px-4 py-2 font-bold">Simpan Review</button>
</form></div>
@endsection
