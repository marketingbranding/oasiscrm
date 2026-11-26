# WP-3.1-PC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans.

**Goal:** Memindahkan proyek dan data project-scoped antar cabang secara atomik, ter-audit, dapat di-rollback, dengan wizard owner.

**Architecture:** `ProjectTransferService` menjadi batas bisnis baru; `ProjectAdministrationService` tetap menolak perpindahan biasa. Batch menyimpan snapshot row lama, satu transaksi memindahkan parent/child project data, mapping membership, dan state migrasi. Cache rebuild/parity dipanggil setelah commit melalui service yang sudah ada.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Alpine.js, PHPUnit, SQLite/MariaDB 11.8.

**Spec:** `docs/PRD_V3_WP_3_1_PINDAH_CABANG_TAHAP_A.md`

## Global Constraints

- Tidak mengubah migration lama.
- Satu transaksi DB untuk seluruh pemindahan.
- Tidak ada trigger.
- Tidak memindahkan cache Sheet; rebuild dari sheet masing-masing.
- Wizard hanya superadmin/owner dan ditolak saat impersonate.
- `pending_create` bukan blocker; Lead terkirim ditentukan `last_synced_at` atau hasil remote.
- Rollback menolak row yang berubah setelah batch.

## Review Focus

- Branch/project mismatch ditolak sebelum update.
- Lead `external_sync_id` tanpa `last_synced_at` tetap dapat dipindah.
- Satu child update gagal membuat seluruh transaksi rollback.
- Mapping Keduanya tidak mengubah role/permission.
- Rollback concurrency tidak menimpa perubahan baru.

## Task List

### Phase 1: Backend contract
- [ ] Migration/model batch snapshot.
- [ ] Dry-run impact/blocker/warning.
- [ ] Atomic execute, mapping, snapshots, ActivityLog.
- [ ] Rollback with updated_at guard.

### Phase 2: HTTP/UI
- [ ] Owner-only routes/controller/requests.
- [ ] Wizard Blade + Alpine states.
- [ ] Project edit redirect guidance.

### Phase 3: Verification
- [ ] Feature tests for authorization, mapping, blockers, atomicity, idempotency, rollback.
- [ ] MariaDB 11.8 gladi and UAT runbook.
- [ ] Build and push.
