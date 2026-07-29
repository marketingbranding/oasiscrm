@extends('layouts.crm')

@section('title', 'Design System - OASIS CRM')

@section('page-title', 'OASIS Design System 2.0')

@section('page-description')
    Referensi internal untuk token, primitive, interaction states, dan aturan migrasi antarmuka CRM.
@endsection

@section('content')
    <div data-testid="design-system-showcase" class="space-y-8">
        <x-crm.alert variant="info" title="Foundation / Phase 1">
            Halaman ini memakai data sintetis dan tidak mengubah data operasional. Primitive yang belum ditampilkan tetap berstatus legacy atau future target.
        </x-crm.alert>

        <x-crm.section id="design-principles" eyebrow="01 / Arah" title="Prinsip Desain" description="Modern operational workspace dengan identitas retro workstation yang terkendali.">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach([
                    ['Informasi sebelum dekorasi', 'Data dan status kerja harus terbaca sebelum aksen visual.'],
                    ['Aksi sebelum animasi', 'Interaksi cepat dan dapat diprediksi lebih penting daripada efek.'],
                    ['Hierarki sebelum kepadatan', 'Pengelompokan yang jelas menjaga workspace tetap ringkas.'],
                    ['Konsistensi sebelum variasi', 'Gunakan primitive yang sama sebelum membuat pola lokal baru.'],
                ] as [$title, $description])
                    <x-crm.card variant="muted">
                        <div class="crm-type-card-title">{{ $title }}</div>
                        <p class="mt-2 crm-type-compact text-[var(--oasis-text-muted)]">{{ $description }}</p>
                    </x-crm.card>
                @endforeach
            </div>
        </x-crm.section>

        <x-crm.section id="design-colors" eyebrow="02 / Foundation" title="Warna & Surface" description="Semantic state tidak boleh digantikan oleh module accent.">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach([
                    ['Page', '--oasis-page-bg'], ['Surface', '--oasis-surface'], ['Muted', '--oasis-surface-muted'], ['Selected', '--oasis-surface-selected'],
                    ['Brand Yellow', '--oasis-yellow'], ['Success', '--oasis-success'], ['Warning', '--oasis-warning'], ['Danger', '--oasis-danger'],
                    ['Information', '--oasis-info'], ['Dashboard', '--oasis-accent-dashboard'], ['Database', '--oasis-accent-database'], ['Planner', '--oasis-accent-planner'],
                ] as [$label, $token])
                    <div class="border border-[var(--oasis-border)] bg-white p-3">
                        <div class="h-12 border border-black/20" style="background: var({{ $token }})"></div>
                        <div class="mt-2 crm-type-label">{{ $label }}</div>
                        <code class="crm-type-system text-[var(--oasis-text-muted)]">{{ $token }}</code>
                    </div>
                @endforeach
            </div>
        </x-crm.section>

        <x-crm.section id="design-spacing" eyebrow="03 / Rhythm" title="Spacing, Border & Shadow">
            <div class="grid gap-6 lg:grid-cols-2">
                <x-crm.card>
                    <x-slot:header>4px spacing rhythm</x-slot:header>
                    <div class="space-y-2">
                        @foreach([1 => '4px', 2 => '8px', 3 => '12px', 4 => '16px', 5 => '20px', 6 => '24px', 8 => '32px', 10 => '40px', 12 => '48px', 16 => '64px'] as $step => $label)
                            <div class="flex items-center gap-3">
                                <code class="w-16 crm-type-system">space-{{ $step }}</code>
                                <span class="h-3 bg-[var(--oasis-info)]" style="width: var(--oasis-space-{{ $step }})"></span>
                                <span class="crm-type-caption text-[var(--oasis-text-muted)]">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-crm.card>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-crm.card>Subtle border / flat surface</x-crm.card>
                    <x-crm.card variant="raised">Raised / subtle shadow</x-crm.card>
                    <x-crm.card variant="emphasis">Strong operational panel</x-crm.card>
                    <x-crm.card variant="muted">Muted nested surface</x-crm.card>
                </div>
            </div>
        </x-crm.section>

        <x-crm.section id="design-typography" eyebrow="04 / Type" title="Typography Roles">
            <div class="space-y-3 border-l-2 border-black pl-4">
                <div class="crm-type-display">Display / OASIS</div>
                <div class="crm-type-page-title">Page Title / Command Center</div>
                <div class="crm-type-section-title">Section Title / Aktivitas</div>
                <div class="crm-type-card-title">Card Title / Ringkasan</div>
                <div class="crm-type-body">Body data memakai Times New Roman sebagai aksen editorial yang disengaja.</div>
                <div class="crm-type-label">Label / Cabang Utama</div>
                <div class="crm-type-caption text-[var(--oasis-text-muted)]">Caption / diperbarui beberapa saat lalu</div>
                <div class="crm-type-system">SYSTEM / SYNC_READY / 09:42</div>
            </div>
        </x-crm.section>

        <x-crm.section id="design-buttons" eyebrow="05 / Actions" title="Buttons & Icon Buttons" description="Caller tetap menentukan permission dan memilih link atau button sesuai semantik.">
            <div class="flex flex-wrap gap-3">
                <x-crm.button variant="primary">Simpan</x-crm.button>
                <x-crm.button variant="primary" accent="database">Sinkronkan</x-crm.button>
                <x-crm.button variant="secondary">Batal</x-crm.button>
                <x-crm.button variant="ghost">Detail</x-crm.button>
                <x-crm.button variant="text" href="#design-buttons">Pelajari</x-crm.button>
                <x-crm.button variant="danger">Hapus</x-crm.button>
                <x-crm.button loading>Memproses</x-crm.button>
                <x-crm.button disabled>Nonaktif</x-crm.button>
                <x-crm.icon-button label="Tutup contoh">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </x-crm.icon-button>
            </div>
        </x-crm.section>

        <x-crm.section id="design-status" eyebrow="06 / States" title="Badges, Alerts & Feedback">
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach(['neutral', 'info', 'pending', 'processing', 'success', 'warning', 'danger', 'inactive', 'archived'] as $variant)
                    <x-crm.status-badge :variant="$variant">{{ $variant }}</x-crm.status-badge>
                @endforeach
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                <x-crm.alert variant="success" title="Berhasil">Perubahan contoh berhasil disimpan.</x-crm.alert>
                <x-crm.alert variant="warning" title="Perlu diperiksa">Data contoh belum lengkap.</x-crm.alert>
                <x-crm.alert variant="error" title="Tidak dapat diproses">Periksa field yang ditandai.</x-crm.alert>
                <x-crm.alert variant="info" title="Informasi">Status sinkronisasi tetap berbeda dari loading biasa.</x-crm.alert>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-crm.button variant="secondary" @click="window.oasisToast('Contoh operasi berhasil.', 'success')">Toast sukses</x-crm.button>
                <x-crm.button variant="secondary" @click="window.oasisToast('Contoh perlu diperiksa.', 'warning')">Toast warning</x-crm.button>
                <x-crm.button variant="secondary" @click="window.oasisToast('Contoh operasi gagal.', 'error')">Toast error</x-crm.button>
            </div>
        </x-crm.section>

        <x-crm.section id="design-content-states" eyebrow="07 / Content" title="Empty & Loading States">
            <div class="grid gap-3 lg:grid-cols-2">
                <x-crm.card>
                    <x-crm.empty-state title="Belum ada data contoh" description="Data baru akan muncul pada area ini.">
                        <x-slot:actions><x-crm.button variant="secondary">Tambah contoh</x-crm.button></x-slot:actions>
                    </x-crm.empty-state>
                </x-crm.card>
                <x-crm.card><x-crm.loading-state label="Memuat data contoh..." /></x-crm.card>
            </div>
        </x-crm.section>

        <x-crm.section id="design-forms" eyebrow="08 / Input" title="Form Composition & Pickers" description="Label tetap terlihat; validation dan old input tetap menjadi tanggung jawab caller/FormRequest.">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-crm.field label="Nama Proyek" for="demo-project" required hint="Gunakan nama sintetis pada showcase." error="Contoh pesan validasi inline.">
                    <input id="demo-project" class="crm-control" value="Proyek Contoh" aria-describedby="demo-project-hint demo-project-error" aria-invalid="true">
                </x-crm.field>
                <x-crm.field label="Status" for="demo-status">
                    <select id="demo-status" class="crm-control"><option>Aktif</option><option>Nonaktif</option></select>
                </x-crm.field>
                <x-crm.field label="Tanggal" for="demo-date" hint="Date picker existing; keyboard grid masih legacy gap.">
                    <x-crm.date-field id="demo-date" name="demo_date" value="2026-07-29" />
                </x-crm.field>
                <x-crm.field label="Waktu" for="demo-time">
                    <x-crm.time-field id="demo-time" name="demo_time" value="09:30" />
                </x-crm.field>
            </div>
        </x-crm.section>

        <x-crm.section id="design-toolbar" eyebrow="09 / Lists" title="Toolbar, Filters & Table">
            <x-crm.toolbar label="Contoh toolbar data">
                <label for="demo-search" class="sr-only">Cari data contoh</label>
                <input id="demo-search" type="search" class="crm-control max-w-sm" placeholder="Cari data contoh...">
                <x-crm.filter-chip label="Cabang: Contoh" remove-href="#design-toolbar" />
                <x-slot:actions>
                    <x-crm.button variant="secondary">Export</x-crm.button>
                    <x-crm.button variant="primary">Tambah Data</x-crm.button>
                </x-slot:actions>
            </x-crm.toolbar>
            <div class="crm-table-scroll mt-4 max-h-64">
                <table class="crm-data-table">
                    <thead><tr><th class="crm-row-num">No.</th><th>Nama</th><th>Status</th><th class="crm-actions">Aksi</th></tr></thead>
                    <tbody>
                        <tr><td class="crm-row-num">1</td><td>Data Contoh Alpha</td><td><x-crm.status-badge variant="success">Aktif</x-crm.status-badge></td><td class="crm-actions"><a href="#design-toolbar" class="font-bold text-[#0000ee] underline">Detail</a></td></tr>
                        <tr><td class="crm-row-num">2</td><td>Data Contoh Beta</td><td><x-crm.status-badge variant="pending">Pending</x-crm.status-badge></td><td class="crm-actions"><a href="#design-toolbar" class="font-bold text-[#0000ee] underline">Detail</a></td></tr>
                    </tbody>
                </table>
            </div>
        </x-crm.section>

        <x-crm.section id="design-dialog" eyebrow="10 / Overlay" title="Accessible Modal" description="Escape, initial focus, focus trap, body lock, close control, dan focus restoration tersedia pada shell canonical.">
            <x-crm.button variant="primary" @click="$dispatch('oasis:modal-open', { name: 'design-system-demo', trigger: $el })">Buka modal contoh</x-crm.button>
            <x-crm.modal name="design-system-demo" title="Modal Contoh" description="Tidak ada data yang akan disimpan.">
                <x-crm.field label="Catatan" for="demo-note">
                    <textarea id="demo-note" class="crm-control" rows="3" data-autofocus>Konten sintetis.</textarea>
                </x-crm.field>
                <x-slot:footer>
                    <x-crm.button variant="secondary" @click="hide()">Batal</x-crm.button>
                    <x-crm.button variant="primary" @click="hide(); window.oasisToast('Contoh lokal selesai.', 'success')">Selesai</x-crm.button>
                </x-slot:footer>
            </x-crm.modal>
        </x-crm.section>

        <x-crm.section id="design-accessibility" eyebrow="11 / Contract" title="Responsive & Accessibility Notes">
            <div class="grid gap-3 md:grid-cols-3">
                <x-crm.card variant="muted"><strong class="crm-type-card-title">Keyboard</strong><p class="mt-2 crm-type-compact">Focus visible, semantic controls, Escape overlays, dan modal focus trap.</p></x-crm.card>
                <x-crm.card variant="muted"><strong class="crm-type-card-title">Mobile</strong><p class="mt-2 crm-type-compact">44px targets, wrapping toolbars, viewport-safe dialog, dan table-local scroll.</p></x-crm.card>
                <x-crm.card variant="muted"><strong class="crm-type-card-title">Motion</strong><p class="mt-2 crm-type-compact">100-200ms transitions dan reduced-motion fallback.</p></x-crm.card>
            </div>
        </x-crm.section>
    </div>
@endsection
