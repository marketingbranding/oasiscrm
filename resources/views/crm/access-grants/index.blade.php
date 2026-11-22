@extends('layouts.crm')
@section('title', 'Izin Akses Khusus - Oasis CRM')
@section('content')
<div class="crm-page space-y-5">
<x-crm.page-header variant="canonical" eyebrow="Administrasi sistem" title="Izin Akses Khusus" description="Akses sementara untuk pengganti Koordinator Sales."></x-crm.page-header>
@if($errors->any())<x-crm.alert variant="danger" title="Periksa isian">{{ $errors->first() }}</x-crm.alert>@endif
<x-crm.card padding="lg"><form method="POST" action="{{ route('admin.access-grants.store') }}" class="grid gap-4 md:grid-cols-3">@csrf
<label>Grantee<select name="grantee_user_id" required class="sales-input mt-1">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select>@error('grantee_user_id')<span class="mt-1 block text-xs font-bold text-[#c0392b]">{{ $message }}</span>@enderror</label>
<label>Subjek tim<select name="subject_user_id" required class="sales-input mt-1">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select>@error('subject_user_id')<span class="mt-1 block text-xs font-bold text-[#c0392b]">{{ $message }}</span>@enderror</label>
<label>Modul<select name="module" required class="sales-input mt-1"><option value="sales_leads">Lead</option><option value="sales_daily_plans">Daily Plan</option></select>@error('module')<span class="mt-1 block text-xs font-bold text-[#c0392b]">{{ $message }}</span>@enderror</label>
<label class="flex gap-2"><input type="checkbox" name="include_descendants" value="1"> Sertakan seluruh turunan</label>
<label class="flex gap-2"><input type="checkbox" name="can_view" value="1" checked> Lihat detail penuh</label>
<label class="flex gap-2"><input type="checkbox" name="can_edit" value="1"> Boleh mengubah</label>
<label>Berlaku sampai<input type="datetime-local" name="expires_at" required class="sales-input mt-1">@error('expires_at')<span class="mt-1 block text-xs font-bold text-[#c0392b]">{{ $message }}</span>@enderror</label>
<label class="md:col-span-2">Alasan<textarea name="reason" required class="sales-input mt-1"></textarea>@error('reason')<span class="mt-1 block text-xs font-bold text-[#c0392b]">{{ $message }}</span>@enderror</label>
<div><x-crm.button type="submit" variant="primary">Berikan Akses</x-crm.button></div></form></x-crm.card>
<x-crm.card padding="lg"><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Grantee</th><th>Subjek</th><th>Modul</th><th>Berlaku</th><th>Status</th><th></th></tr></thead><tbody>@foreach($grants as $grant)<tr><td>{{ $grant->grantee->name }}</td><td>{{ $grant->subject->name }}{{ $grant->include_descendants ? ' + turunan' : '' }}</td><td>{{ $grant->module }}</td><td>{{ $grant->expires_at->format('d/m/Y H:i') }}</td><td>{{ $grant->revoked_at ? 'Dicabut' : ($grant->expires_at->isFuture() ? 'Aktif' : 'Kedaluwarsa') }}</td><td>@if(! $grant->revoked_at && $grant->expires_at->isFuture())<form method="POST" action="{{ route('admin.access-grants.revoke', $grant) }}">@csrf @method('PATCH')<input name="reason" required placeholder="Alasan cabut" class="sales-input"><button class="text-red-700 font-bold" type="submit">Cabut</button></form>@endif</td></tr>@endforeach</tbody></table></div>{{ $grants->links() }}</x-crm.card>
</div>
@endsection
