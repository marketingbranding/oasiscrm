@extends('layouts.crm')

@section('title', 'Maintenance OASIS')

@section('content')
<div class="crm-page" x-data>
    <x-crm.page-header
        variant="canonical"
        eyebrow="Administrasi sistem"
        title="Full Maintenance Mode"
        description="Blokir sementara seluruh CRM terproteksi untuk pengguna biasa tanpa menghentikan scheduler, queue, atau perintah console."
    >
        <x-slot:actions>
            @if($setting->enabled)
                <x-crm.button variant="danger" @click="$dispatch('oasis:modal-open', { name: 'disable-maintenance', trigger: $el })">
                    Nonaktifkan maintenance
                </x-crm.button>
            @else
                <x-crm.button variant="primary" accent="administration" @click="$dispatch('oasis:modal-open', { name: 'enable-maintenance', trigger: $el })">
                    Aktifkan maintenance
                </x-crm.button>
            @endif
        </x-slot:actions>
    </x-crm.page-header>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(280px,0.8fr)]">
        <x-crm.card variant="emphasis" padding="lg">
            <x-slot:header>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-[Helvetica] text-sm font-bold uppercase tracking-wide">Status operasional</h2>
                    <x-crm.status-badge :variant="$setting->enabled ? 'danger' : 'success'">
                        {{ $setting->enabled ? 'Maintenance aktif' : 'OASIS tersedia' }}
                    </x-crm.status-badge>
                </div>
            </x-slot:header>

            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Judul publik</dt>
                    <dd class="mt-1 break-words font-['Times_New_Roman'] text-lg">{{ $setting->title }}</dd>
                </div>
                <div>
                    <dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Perkiraan selesai</dt>
                    <dd class="mt-1 font-['Times_New_Roman'] text-lg">
                        {{ $setting->estimated_end_at?->timezone(config('app.timezone'))->translatedFormat('d F Y, H.i T') ?? 'Belum ditentukan' }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Pesan publik</dt>
                    <dd class="mt-1 whitespace-pre-line break-words font-['Times_New_Roman'] leading-relaxed">{{ $setting->message }}</dd>
                </div>
                @if($setting->enabled_at)
                    <div>
                        <dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Diaktifkan</dt>
                        <dd class="mt-1 text-sm">
                            {{ $setting->enabled_at->timezone(config('app.timezone'))->translatedFormat('d F Y, H.i T') }}
                            @if($setting->enabledBy) oleh {{ $setting->enabledBy->name }} @endif
                        </dd>
                    </div>
                @endif
                @if($setting->disabled_at)
                    <div>
                        <dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Terakhir dinonaktifkan</dt>
                        <dd class="mt-1 text-sm">
                            {{ $setting->disabled_at->timezone(config('app.timezone'))->translatedFormat('d F Y, H.i T') }}
                            @if($setting->disabledBy) oleh {{ $setting->disabledBy->name }} @endif
                        </dd>
                    </div>
                @endif
            </dl>
        </x-crm.card>

        <x-crm.card variant="muted" padding="lg">
            <x-slot:header>
                <h2 class="font-[Helvetica] text-sm font-bold uppercase tracking-wide">Dampak maintenance</h2>
            </x-slot:header>
            <ul class="list-disc space-y-2 pl-5 font-['Times_New_Roman'] leading-relaxed">
                <li>Pengguna biasa menerima halaman OASIS dengan status HTTP 503.</li>
                <li>Endpoint AJAX menerima respons JSON 503 dan operasi tulis tidak dijalankan.</li>
                <li>Login, pemulihan akun, perubahan password wajib, dan logout tetap tersedia.</li>
                <li>Pengguna dengan izin bypass tetap dapat menggunakan CRM dan halaman ini.</li>
                <li>Scheduler, queue, sinkronisasi terjadwal, dan console tidak dihentikan.</li>
            </ul>
        </x-crm.card>
    </div>

    <x-crm.section id="module-maintenance" title="Module Maintenance" description="Batasi modul tertentu tanpa menyembunyikan navigasi atau mengubah full maintenance mode." class="mt-6">
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach($moduleStatuses as $module)
                @php($modalName = 'module-maintenance-'.$module['module_key'])
                <x-crm.card :variant="$module['is_enabled'] ? 'emphasis' : 'muted'" padding="md">
                    <x-slot:header>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-[Helvetica] text-sm font-bold">{{ $module['module_label'] }}</h3>
                                @if($module['module_description'])<p class="mt-1 text-sm text-gray-600">{{ $module['module_description'] }}</p>@endif
                            </div>
                            <x-crm.status-badge :variant="$module['is_enabled'] ? 'danger' : 'success'">
                                {{ $module['is_enabled'] ? 'Maintenance aktif' : 'Tersedia' }}
                            </x-crm.status-badge>
                        </div>
                    </x-slot:header>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="sm:col-span-2"><dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Pesan</dt><dd class="mt-1 whitespace-pre-line break-words">{{ $module['message'] ?: 'Belum ada pesan khusus.' }}</dd></div>
                        <div><dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Perkiraan selesai</dt><dd class="mt-1">{{ $module['estimated_end_at'] ? \Illuminate\Support\Carbon::parse($module['estimated_end_at'])->timezone(config('app.timezone'))->translatedFormat('d F Y, H.i T') : 'Belum ditentukan' }}</dd></div>
                        <div><dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Terakhir diperbarui</dt><dd class="mt-1">{{ $module['updated_at'] ? \Illuminate\Support\Carbon::parse($module['updated_at'])->timezone(config('app.timezone'))->translatedFormat('d F Y, H.i T') : 'Belum pernah' }}@if($module['updated_by']) oleh {{ $module['updated_by'] }}@endif</dd></div>
                    </dl>
                    @if(auth()->user()->isSuperadmin())
                        <x-slot:footer>
                            <div class="flex flex-wrap justify-end gap-2">
                                <x-crm.button variant="secondary" @click="$dispatch('oasis:modal-open', { name: '{{ $modalName }}', trigger: $el })">{{ $module['is_enabled'] ? 'Perbarui' : 'Atur' }}</x-crm.button>
                                @if($module['is_enabled'])
                                    <form method="POST" action="{{ route('admin.maintenance.modules.disable', $module['module_key']) }}">@csrf @method('PUT')<x-crm.button type="submit" variant="danger">Nonaktifkan</x-crm.button></form>
                                @endif
                            </div>
                        </x-slot:footer>
                    @endif
                </x-crm.card>

                @if(auth()->user()->isSuperadmin())
                    <x-crm.modal name="{{ $modalName }}" title="{{ $module['is_enabled'] ? 'Perbarui' : 'Aktifkan' }} maintenance {{ $module['module_label'] }}" :initially-open="$errors->any() && old('module_key') === $module['module_key']">
                        <form method="POST" action="{{ $module['is_enabled'] ? route('admin.maintenance.modules.update', $module['module_key']) : route('admin.maintenance.modules.enable', $module['module_key']) }}" class="space-y-4">
                            @csrf @method('PUT')
                            <input type="hidden" name="module_key" value="{{ $module['module_key'] }}">
                            <x-crm.field label="Pesan maintenance" for="message-{{ $module['module_key'] }}" hint="Opsional, maksimal 1.000 karakter." :error="old('module_key') === $module['module_key'] ? $errors->first('message') : null">
                                <textarea id="message-{{ $module['module_key'] }}" name="message" rows="4" maxlength="1000" class="crm-control">{{ old('module_key') === $module['module_key'] ? old('message') : $module['message'] }}</textarea>
                            </x-crm.field>
                            <x-crm.field label="Perkiraan selesai" for="estimate-{{ $module['module_key'] }}" hint="Opsional. Estimasi yang sudah lewat tidak menonaktifkan maintenance otomatis." :error="old('module_key') === $module['module_key'] ? $errors->first('estimated_end_at') : null">
                                <input id="estimate-{{ $module['module_key'] }}" name="estimated_end_at" type="datetime-local" class="crm-control" value="{{ old('module_key') === $module['module_key'] ? old('estimated_end_at') : ($module['estimated_end_at'] ? \Illuminate\Support\Carbon::parse($module['estimated_end_at'])->timezone(config('app.timezone'))->format('Y-m-d\TH:i') : '') }}">
                            </x-crm.field>
                            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <x-crm.button variant="secondary" @click="$dispatch('oasis:modal-close', { name: '{{ $modalName }}' })">Batal</x-crm.button>
                                <x-crm.button type="submit" variant="primary" accent="administration">{{ $module['is_enabled'] ? 'Simpan perubahan' : 'Aktifkan maintenance' }}</x-crm.button>
                            </div>
                        </form>
                    </x-crm.modal>
                @endif
            @endforeach
        </div>
    </x-crm.section>

    @if(! $setting->enabled)
        <x-crm.modal
            name="enable-maintenance"
            title="Aktifkan full maintenance"
            description="Seluruh halaman dan operasi CRM akan diblokir bagi pengguna tanpa izin bypass."
            size="lg"
            :initially-open="$errors->any() && old('maintenance_action') === 'enable'"
        >
            <form method="POST" action="{{ route('admin.maintenance.enable') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="lock_version" value="{{ $setting->lock_version }}">
                <input type="hidden" name="maintenance_action" value="enable">

                <x-crm.field label="Judul publik" for="title" required :error="$errors->first('title')">
                    <input id="title" name="title" type="text" maxlength="160" required class="crm-control" value="{{ old('title', $setting->title) }}">
                </x-crm.field>

                <x-crm.field label="Pesan publik" for="message" required hint="Pesan ini aman dilihat oleh seluruh pengguna." :error="$errors->first('message')">
                    <textarea id="message" name="message" rows="5" maxlength="2000" required class="crm-control">{{ old('message', $setting->message) }}</textarea>
                </x-crm.field>

                <x-crm.field label="Perkiraan selesai" for="estimated_end_at" hint="Opsional. Harus berada di masa mendatang." :error="$errors->first('estimated_end_at')">
                    <input id="estimated_end_at" name="estimated_end_at" type="datetime-local" class="crm-control" value="{{ old('estimated_end_at', $setting->estimated_end_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i')) }}">
                </x-crm.field>

                <x-crm.field label="Konfirmasi" for="enable_confirmation" required hint="Ketik AKTIFKAN MAINTENANCE secara lengkap." :error="$errors->first('confirmation')">
                    <input id="enable_confirmation" name="confirmation" type="text" autocomplete="off" required data-autofocus class="crm-control" value="{{ old('confirmation') }}">
                </x-crm.field>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-crm.button variant="secondary" @click="$dispatch('oasis:modal-close', { name: 'enable-maintenance' })">Batal</x-crm.button>
                    <x-crm.button type="submit" variant="primary" accent="administration">Aktifkan maintenance</x-crm.button>
                </div>
            </form>
        </x-crm.modal>
    @else
        <x-crm.modal
            name="disable-maintenance"
            title="Nonaktifkan full maintenance"
            description="Akses CRM pengguna biasa akan segera dipulihkan tanpa memerlukan login ulang."
            :initially-open="$errors->any() && old('maintenance_action') === 'disable'"
        >
            <form method="POST" action="{{ route('admin.maintenance.disable') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="lock_version" value="{{ $setting->lock_version }}">
                <input type="hidden" name="maintenance_action" value="disable">

                <x-crm.field label="Konfirmasi" for="disable_confirmation" required hint="Ketik NONAKTIFKAN MAINTENANCE secara lengkap." :error="$errors->first('confirmation')">
                    <input id="disable_confirmation" name="confirmation" type="text" autocomplete="off" required data-autofocus class="crm-control" value="{{ old('confirmation') }}">
                </x-crm.field>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-crm.button variant="secondary" @click="$dispatch('oasis:modal-close', { name: 'disable-maintenance' })">Batal</x-crm.button>
                    <x-crm.button type="submit" variant="danger">Nonaktifkan maintenance</x-crm.button>
                </div>
            </form>
        </x-crm.modal>
    @endif
</div>
@endsection
