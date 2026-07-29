<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DesignSystemComponentTest extends TestCase
{
    public function test_button_variants_links_disabled_and_loading_states_render_semantically(): void
    {
        $primary = Blade::render('<x-crm.button variant="primary" accent="sales">Simpan</x-crm.button>');
        $link = Blade::render('<x-crm.button href="/contoh" variant="secondary">Buka</x-crm.button>');
        $disabled = Blade::render('<x-crm.button disabled>Nonaktif</x-crm.button>');
        $loading = Blade::render('<x-crm.button loading>Memproses</x-crm.button>');
        $disabledLink = Blade::render('<x-crm.button href="/contoh" disabled @click="run()">Terkunci</x-crm.button>');

        $this->assertStringContainsString('crm-button--primary', $primary);
        $this->assertStringNotContainsString('<span>Simpan</span>', $primary);
        $this->assertStringContainsString('data-accent="sales"', $primary);
        $this->assertStringContainsString('/contoh', $link);
        $this->assertStringContainsString('<a ', $link);
        $this->assertStringContainsString('disabled', $disabled);
        $this->assertStringContainsString('aria-busy="true"', $loading);
        $this->assertStringContainsString('crm-button-spinner', $loading);
        $this->assertStringContainsString('aria-disabled="true"', $disabledLink);
        $this->assertStringNotContainsString('@click', $disabledLink);
        $this->assertStringNotContainsString('run()', $disabledLink);
    }

    public function test_icon_button_requires_an_accessible_name(): void
    {
        $html = Blade::render('<x-crm.icon-button label="Tutup"><svg aria-hidden="true"></svg></x-crm.icon-button>');

        $this->assertStringContainsString('aria-label="Tutup"', $html);
        $this->assertStringContainsString('title="Tutup"', $html);
        $this->assertStringContainsString('crm-icon-button', $html);
    }

    public function test_card_and_section_support_composable_slots_and_attribute_merging(): void
    {
        $card = Blade::render('<x-crm.card variant="raised" class="demo-card">Isi</x-crm.card>');
        $section = Blade::render('<x-crm.section id="demo" title="Prinsip" description="Keterangan">Isi</x-crm.section>');
        $cardSource = file_get_contents(resource_path('views/components/crm/card.blade.php'));
        $sectionSource = file_get_contents(resource_path('views/components/crm/section.blade.php'));

        $this->assertStringContainsString('crm-card--raised', $card);
        $this->assertStringContainsString('demo-card', $card);
        $this->assertStringContainsString('@isset($header)', $cardSource);
        $this->assertStringContainsString('aria-labelledby="demo-title"', $section);
        $this->assertStringContainsString('id="demo-title"', $section);
        $this->assertStringContainsString('@isset($actions)', $sectionSource);
    }

    public function test_status_badge_requires_explicit_semantic_output(): void
    {
        $success = Blade::render('<x-crm.status-badge variant="success">Aktif</x-crm.status-badge>');
        $fallback = Blade::render('<x-crm.status-badge variant="unknown">Status</x-crm.status-badge>');

        $this->assertStringContainsString('crm-status-badge--success', $success);
        $this->assertStringContainsString('>Aktif</span>', $success);
        $this->assertStringContainsString('crm-status-badge--neutral', $fallback);
    }

    public function test_page_header_preserves_legacy_contract_and_supports_canonical_mode(): void
    {
        $legacy = Blade::render('<x-crm.page-header title="Pengguna" color="#8c9ae0" />');
        $canonical = Blade::render('<x-crm.page-header variant="canonical" title="Design System" description="Panduan"><x-slot:actions>Aksi</x-slot:actions></x-crm.page-header>');

        $this->assertStringContainsString('border-2 border-black', $legacy);
        $this->assertStringContainsString('background-color: #8c9ae0', $legacy);
        $this->assertStringContainsString('crm-page-header-title', $canonical);
        $this->assertStringContainsString('crm-page-header-description', $canonical);
        $this->assertStringContainsString('crm-page-header-actions', $canonical);
    }

    public function test_alert_empty_and_loading_states_have_semantic_markup(): void
    {
        $alert = Blade::render('<x-crm.alert variant="error" title="Gagal">Coba lagi.</x-crm.alert>');
        $empty = Blade::render('<x-crm.empty-state title="Belum ada data" description="Tambahkan data pertama." />');
        $loading = Blade::render('<x-crm.loading-state label="Memuat laporan..." />');

        $this->assertStringContainsString('role="alert"', $alert);
        $this->assertStringContainsString('crm-alert--error', $alert);
        $this->assertStringContainsString('crm-empty-state-title', $empty);
        $this->assertStringContainsString('Tambahkan data pertama.', $empty);
        $this->assertStringContainsString('role="status"', $loading);
        $this->assertStringContainsString('Memuat laporan...', $loading);
    }

    public function test_toolbar_filter_chip_and_field_preserve_composition_and_labels(): void
    {
        $toolbar = Blade::render('<x-crm.toolbar label="Daftar pengguna">Cari</x-crm.toolbar>');
        $chip = Blade::render('<x-crm.filter-chip label="Cabang: Solo" remove-href="/reset" />');
        $field = Blade::render('<x-crm.field label="Nama" for="name" required hint="Nama lengkap" error="Nama wajib diisi"><input id="name"></x-crm.field>');

        $this->assertStringContainsString('aria-label="Daftar pengguna"', $toolbar);
        $this->assertStringContainsString('crm-filter-chip-remove', $chip);
        $this->assertStringContainsString('aria-label="Hapus filter Cabang: Solo"', $chip);
        $this->assertStringContainsString('for="name"', $field);
        $this->assertStringContainsString('id="name-hint"', $field);
        $this->assertStringContainsString('id="name-error"', $field);
        $this->assertStringContainsString('role="alert"', $field);
    }

    public function test_modal_has_accessible_dialog_and_focus_management_contract(): void
    {
        $html = Blade::render('<x-crm.modal name="demo" title="Contoh Dialog" description="Dialog aman."><button data-autofocus>Simpan</button></x-crm.modal>');
        $source = file_get_contents(resource_path('js/crm-modal.js'));

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="crm-modal-demo-title"', $html);
        $this->assertStringContainsString('aria-describedby="crm-modal-demo-description"', $html);
        $this->assertStringContainsString('aria-label="Tutup dialog"', $html);
        $this->assertStringContainsString("event.key === 'Escape'", $source);
        $this->assertStringContainsString("event.key !== 'Tab'", $source);
        $this->assertStringContainsString('lockBodyScroll(this.lockOwner)', $source);
        $this->assertStringContainsString('if (this.open)', $source);
        $this->assertStringContainsString('this.trigger?.focus()', $source);
    }
}
