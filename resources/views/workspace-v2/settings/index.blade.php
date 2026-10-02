@extends('workspace-v2.layouts.app')
@section('title', 'Pengaturan | Workspace OASIS')
@section('content')
    @include('workspace-v2.components.page-header', ['eyebrow' => 'SISTEM', 'title' => 'Pengaturan', 'description' => 'Akses konfigurasi tetap mengikuti permission dan halaman administrasi existing.'])
    <section class="workspace-v2-settings-grid"><a class="workspace-v2-setting-card" href="{{ route('admin-users.index') }}"><span>01</span><strong>Pengguna & akses</strong><small>Role, permission, undangan, dan assignment.</small></a><a class="workspace-v2-setting-card" href="{{ route('branches.index') }}"><span>02</span><strong>Organisasi</strong><small>Cabang, proyek, dan struktur kerja.</small></a><a class="workspace-v2-setting-card" href="{{ route('admin.system-health') }}"><span>03</span><strong>Kesehatan sistem</strong><small>Integrasi dan status infrastruktur.</small></a></section>
@endsection
