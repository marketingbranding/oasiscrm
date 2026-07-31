@extends('layouts.crm')
@section('title', 'Detail Pengguna - Oasis CRM')
@section('content')
@php
    $statusVariant = match ($user->account_status) {
        \App\Enums\AccountStatus::PendingInvitation => 'pending',
        \App\Enums\AccountStatus::Invited => 'processing',
        \App\Enums\AccountStatus::Active => 'success',
        \App\Enums\AccountStatus::Suspended => 'warning',
        \App\Enums\AccountStatus::Inactive => 'inactive',
        default => 'archived',
    };
    $statusLabel = str_replace('_', ' ', ucwords($user->account_status->value));
@endphp
<div x-data="{
    lifecycleAction: null,
    submitting: false,
    openLifecycle(action) {
        this.lifecycleAction = action;
        this.submitting = false;
        window.dispatchEvent(new CustomEvent('oasis:modal-open', { detail: { name: 'lifecycle-confirm', trigger: document.activeElement } }));
    },
    closeLifecycle() {
        this.lifecycleAction = null;
        window.dispatchEvent(new CustomEvent('oasis:modal-close', { detail: { name: 'lifecycle-confirm', reason: 'cancel' } }));
    }
}">
    <x-crm.page-header
        variant="canonical"
        title="Detail Pengguna"
        eyebrow="Administrasi Pengguna"
        description="Siklus hidup akun, penugasan, undangan, dan riwayat audit untuk satu pengguna."
    >
        <x-slot:actions>
            <x-crm.status-badge :variant="$statusVariant">{{ $statusLabel }}</x-crm.status-badge>
            <x-crm.button href="{{ route('admin-users.index') }}" variant="secondary">Kembali ke Daftar</x-crm.button>
        </x-slot:actions>
    </x-crm.page-header>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="crm-section bg-white lg:col-span-2">
            <div class="bg-black text-white p-2 text-xs font-bold">AKUN DAN ORGANISASI</div>
            <dl class="grid gap-3 p-4 text-sm sm:grid-cols-2">
                <div><dt class="font-bold">Nama</dt><dd>{{ $user->name }}</dd></div>
                <div><dt class="font-bold">Email</dt><dd>{{ $user->email }}</dd></div>
                <div><dt class="font-bold">Telepon</dt><dd>{{ $user->phone ?: '-' }}</dd></div>
                <div><dt class="font-bold">Peran</dt><dd>{{ $user->role?->name }}</dd></div>
                <div><dt class="font-bold">Peran Tambahan</dt><dd>{{ $user->roles->pluck('name')->join(', ') ?: '-' }}</dd></div>
                <div><dt class="font-bold">Cabang Utama</dt><dd>{{ $user->branch?->name ?: '-' }}</dd></div>
                <div><dt class="font-bold">Cabang Tambahan</dt><dd>{{ $user->branches->where('id','!=',$user->branch_id)->pluck('name')->join(', ') ?: '-' }}</dd></div>
                <div><dt class="font-bold">Proyek</dt><dd>{{ $user->assignedProjects->pluck('project_name')->join(', ') ?: '-' }}</dd></div>
                <div><dt class="font-bold">Atasan</dt><dd>{{ $user->supervisor?->name ?: '-' }}</dd></div>
                <div><dt class="font-bold">Status Akun</dt><dd><x-crm.status-badge :variant="$statusVariant">{{ $statusLabel }}</x-crm.status-badge></dd></div>
                <div><dt class="font-bold">Email Terverifikasi</dt><dd>{{ $user->email_verified_at ? $user->email_verified_at->format('d/m/Y H:i') : 'Belum' }}</dd></div>
                <div><dt class="font-bold">Password Diubah</dt><dd>{{ $user->password_changed_at ? $user->password_changed_at->format('d/m/Y H:i') : 'Belum' }}</dd></div>
                <div><dt class="font-bold">Login Terakhir</dt><dd>{{ $user->last_login_at?->format('d/m/Y H:i') ?: '-' }}</dd></div>
                @if($user->anonymized_at)<div><dt class="font-bold">Dianonimkan</dt><dd>{{ $user->anonymized_at->format('d/m/Y H:i') }}</dd></div>@endif
            </dl>
        </section>

        <aside class="crm-section bg-white">
            <div class="bg-black text-white p-2 text-xs font-bold">TINDAKAN</div>
            <div class="space-y-2 p-3 text-xs">
                @if($user->account_status !== \App\Enums\AccountStatus::Anonymized)
                @can('update',$user)<a href="{{ route('admin-users.edit',$user) }}" class="block border-2 border-black p-2 font-bold text-center">EDIT</a>@endcan
                @endif

                @if(in_array($user->account_status,[\App\Enums\AccountStatus::PendingInvitation,\App\Enums\AccountStatus::Invited]))
                @can('users.invite')<button type="button" @click="openLifecycle({ url: @js(route($user->account_status===\App\Enums\AccountStatus::PendingInvitation?'admin-users.invitation.send':'admin-users.invitation.resend',$user)), method: 'POST', label: '{{ $user->account_status===\App\Enums\AccountStatus::PendingInvitation?'Kirim Undangan':'Kirim Ulang Undangan' }}', reasonRequired: false })" class="w-full border-2 border-black bg-[#8c9ae0] p-2 font-bold">{{ $user->account_status===\App\Enums\AccountStatus::PendingInvitation?'SEND INVITATION':'RESEND INVITATION' }}</button>@endcan
                @if($user->latestInvitation?->isUsable())@can('users.invite')<button type="button" @click="openLifecycle({ url: @js(route('admin-users.invitation.revoke',$user)), method: 'PATCH', label: 'Cabut Undangan', reasonRequired: false })" class="w-full border-2 border-black p-2 font-bold">REVOKE INVITATION</button>@endcan @endif
                @endif

                @can('users.reset_password')<button type="button" @click="openLifecycle({ url: @js(route('admin-users.reset-access',$user)), method: 'POST', label: 'Kirim Pemulihan Akses', reasonRequired: false })" class="w-full border-2 border-black p-2 font-bold">RESET ACCESS</button>@endcan

                @php($statusActions = match($user->account_status) {
                    \App\Enums\AccountStatus::Active => ['suspend' => 'SUSPEND', 'deactivate' => 'DEACTIVATE'],
                    \App\Enums\AccountStatus::Suspended => ['reactivate' => 'REACTIVATE', 'deactivate' => 'DEACTIVATE'],
                    \App\Enums\AccountStatus::Inactive => ['reactivate' => 'REACTIVATE'],
                    \App\Enums\AccountStatus::Anonymized => [],
                    default => ['deactivate' => 'DEACTIVATE'],
                })
                @foreach($statusActions as $action => $label)
                @can('users.'.$action)<button type="button" @click="openLifecycle({ url: @js(route('admin-users.'.$action,$user)), method: 'PATCH', label: '{{ ucwords(str_replace('_',' ',$action)) }} Akun', reasonRequired: true, reasonLabel: 'Alasan {{ str_replace('_',' ',ucwords($action)) }}' })" class="w-full border-2 border-black p-2 font-bold">{{ $label }}</button>@endcan
                @endforeach

                @if($user->account_status !== \App\Enums\AccountStatus::Anonymized)
                @can('users.anonymize')<button type="button" @click="openLifecycle({ url: @js(route('admin-users.anonymize',$user)), method: 'PATCH', label: 'Anonimkan Akun', reasonRequired: true, reasonLabel: 'Alasan anonimisasi' })" class="w-full border-2 border-black bg-[#d77a7a] p-2 font-bold text-white">ANONIMKAN</button>@endcan
                @endif

                @if($user->account_status === \App\Enums\AccountStatus::Inactive)
                @can('users.release_email')<button type="button" @click="openLifecycle({ url: @js(route('admin-users.release-email',$user)), method: 'PATCH', label: 'Lepas Email untuk Dipakai Ulang', reasonRequired: true, reasonLabel: 'Alasan pelepasan email' })" class="w-full border-2 border-black p-2 font-bold">LEPAS EMAIL</button>@endcan
                @endif

                @if($user->account_status === \App\Enums\AccountStatus::PendingInvitation)
                @can('users.delete_permanently')
                <button type="button" @click="openLifecycle({ url: @js(route('admin-users.destroy',$user)), method: 'DELETE', label: 'Hapus Draf Akun Secara Permanen', reasonRequired: true, reasonLabel: 'Alasan penghapusan' })" class="w-full border-2 border-black bg-[#c0392b] p-2 font-bold text-white">HAPUS DRAF</button>
                @if(count($deletionBlockers) > 0)
                <x-crm.alert variant="warning" title="Draf ini tidak dapat dihapus permanen" class="mt-2">
                    <ul class="list-disc pl-5">
                        @foreach($deletionBlockers as $blocker)<li>{{ $blocker }}</li>@endforeach
                    </ul>
                </x-crm.alert>
                @endif
                @endcan
                @endif
            </div>
        </aside>

        <section class="crm-section bg-white">
            <div class="bg-black text-white p-2 text-xs font-bold">RIWAYAT UNDANGAN</div>
            <div class="divide-y-2 divide-black">
                @forelse($user->invitations as $invitation)
                <div class="p-3 text-xs">{{ $invitation->created_at->format('d/m/Y H:i') }} · {{ $invitation->accepted_at?'Diterima':($invitation->revoked_at?'Dicabut':($invitation->expires_at->isPast()?'Kedaluwarsa':'Aktif')) }}<br>Oleh {{ $invitation->inviter?->name ?: '-' }}</div>
                @empty<div class="p-3 text-xs">Belum ada undangan.</div>@endforelse
            </div>
        </section>

        <section class="crm-section bg-white lg:col-span-2">
            <div class="bg-black text-white p-2 text-xs font-bold">RIWAYAT AKTIVITAS</div>
            <div class="max-h-80 divide-y-2 divide-black overflow-y-auto">
                @forelse($user->activityLogs as $log)
                <div class="p-3 text-xs"><strong>{{ $log->description }}</strong><br>{{ $log->created_at->format('d/m/Y H:i') }} · {{ $log->causer?->name ?: 'Sistem' }}</div>
                @empty<div class="p-3 text-xs">Belum ada aktivitas.</div>@endforelse
            </div>
        </section>
    </div>

    <x-crm.modal name="lifecycle-confirm" title="Konfirmasi Tindakan Akun" size="sm">
        <p class="mb-3 text-sm font-['Times_New_Roman']" x-show="lifecycleAction">
            Tindakan <strong x-text="lifecycleAction?.label"></strong> akan dicatat pada riwayat audit akun ini.
        </p>
        <form id="lifecycle-form" method="POST" :action="lifecycleAction?.url" @submit="submitting = true">
            @csrf
            <template x-if="lifecycleAction?.method && lifecycleAction.method !== 'POST'">
                <input type="hidden" name="_method" :value="lifecycleAction.method">
            </template>
            <div x-show="lifecycleAction?.reasonRequired">
                <label for="lifecycle-reason" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase" x-text="lifecycleAction?.reasonLabel || 'Alasan'"></label>
                <input x-ref="lifecycleReason" id="lifecycle-reason" name="reason" minlength="3" maxlength="500"
                       :required="lifecycleAction?.reasonRequired"
                       :data-autofocus="lifecycleAction?.reasonRequired ? '' : null"
                       placeholder="Alasan tindakan" class="w-full border-2 border-black bg-white px-3 py-2 text-sm">
            </div>
        </form>
        <x-slot:footer>
            <x-crm.button type="submit" form="lifecycle-form" variant="danger" data-autofocus x-bind:disabled="submitting">Konfirmasi</x-crm.button>
            <x-crm.button type="button" variant="secondary" @click="closeLifecycle()">Batal</x-crm.button>
        </x-slot:footer>
    </x-crm.modal>
</div>
@endsection
