# WP-1.6 Lead Stages v3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add feature-flagged Lead Baru–UTJ and Gugur lifecycle behavior while preserving legacy status, spreadsheet, historical facts, scope, and routes.

**Architecture:** Add one canonical `lead_stage` value plus Gugur metadata and audited stage history. A dedicated lifecycle service owns v3 transition, correction, and lock rules; existing legacy status remains compatibility data for downstream lifecycle and Sheet. Existing policies and named routes remain boundaries.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent, Blade, Alpine.js, PHPUnit, SQLite tests, existing AppSetting resolver and CRM components.

**Spec:** Approved WP-1.6 compatibility design in conversation; `docs/product/PRD_OASIS_CRM_V3.md` BR-10–BR-14 and §10.

## Global Constraints

- `lead_stages_v3` remains default-off; flag-off behavior stays unchanged.
- Canonical stages are `lead_baru`, `dihubungi`, `tatap_muka`, `survey`, `utj`, `gugur`.
- `documents_completed_at` and `akad_at` remain historical facts, never v3 Lead stages.
- Lead stage changes are scoped through existing policy/workspace services.
- Existing Lead IDs, sync IDs, current status, routes, and Sheet compatibility remain intact.
- No production cutover, Sheet disablement, role migration, Lead transfer, or transaction/NUP work.
- No fake historical stage-history rows during backfill.
- Every behavior change gets one idempotent Indonesian changelog migration with `version = null`.

## Review Focus

- Old lifecycle statuses downstream of UTJ must still map to v3 `utj` without allowing Sales edits after UTJ; test in Task 2 and Task 4.
- A Lead with no timestamps or status must become `lead_baru`; test migration/backfill in Task 1.
- Invalid Gugur reasons and Gugur after UTJ must fail without mutation; test in Task 3.
- Direct URLs, supplemental roles, and out-of-scope Leads must not bypass policy; test in Task 4.
- Sheet pull/push must accept canonical UTJ/Gugur without changing stable identity; test in Task 5.

---

### Task 1: Schema, catalog settings, model, and backfill

**Files:**
- Create: additive migration for `sales_leads` v3 fields and `sales_lead_stage_histories`.
- Create: backfill migration for existing Leads.
- Create: `app/Models/SalesLeadStageHistory.php`.
- Modify: `app/Models/SalesLead.php`.
- Modify: `app/Support/AppSettingCatalog.php`.
- Modify: `resources/views/crm/app-settings/index.blade.php` for list settings.
- Test: `tests/Feature/SalesLeadStageV3MigrationTest.php`, `tests/Unit/SalesLeadStageTest.php`.

**Interfaces:**
- Produces `SalesLead::STAGE_V3`, `SalesLead::stageV3Label()`, and `SalesLeadStageHistory` relation.
- Produces settings `sales_lead.gugur_reasons` and `sales_lead.sales_max_edit_stage`.
- Backfill maps legacy statuses/facts to `lead_stage` without inserting stage history.

- [ ] Write failing tests named for BR-10/BR-11 covering stage constants, mapping, no-history backfill, and list-setting normalization.
- [ ] Run focused tests and observe RED.
- [ ] Add nullable `lead_stage`, `lead_stage_changed_at`, `gugur_at`, `gugur_reason`; add stage-history table with branch/actor/source/operation/reason metadata and unique operation identity.
- [ ] Add model casts, fillable fields, relation, stage labels, and last-activity compatibility without including Berkas/Akad in v3 order.
- [ ] Add catalog list and stage settings with approved defaults; render list values as one textarea line per reason and validate list input.
- [ ] Add idempotent backfill mapping: legacy downstream statuses and `utj_at` to `utj`, then survey/face/contact timestamps, otherwise `lead_baru`; do not create history.
- [ ] Run focused migration/model/settings tests, Pint, and diff check.
- [ ] Commit `feat: add lead stage v3 storage and backfill`.

### Task 2: Canonical stage service and correction flow

**Files:**
- Create: `app/Services/SalesLeadStageV3Service.php`.
- Create: `app/Http/Requests/Crm/UpdateSalesLeadStageV3Request.php`.
- Modify: `app/Http/Controllers/Crm/SalesLeadStageController.php` or add a thin sibling controller using existing route boundary.
- Modify: `app/Policies/SalesLeadPolicy.php`.
- Test: `tests/Feature/SalesLeadStageV3Test.php`.

**Interfaces:**
- `SalesLeadStageV3Service::setStage(SalesLead $lead, string $stage, User $actor, ?string $operationUuid = null): SalesLead`.
- `SalesLeadStageV3Service::correctStage(SalesLead $lead, string $stage, string $reason, User $actor, ?string $operationUuid = null): SalesLead`.
- `SalesLeadStageV3Service::closeAsGugur(SalesLead $lead, string $reason, User $actor, ?string $operationUuid = null): SalesLead`.

- [ ] Write failing BR-10 tests for forward jumps and rejection of normal reverse; BR-11 tests for excluded Berkas/Akad; BR-12 test that no follow-up field changes stage.
- [ ] Run focused tests and observe RED.
- [ ] Implement flag-gated canonical transitions with atomic Lead lock, idempotent operation UUID, audit history, ActivityLog, and legacy current-status compatibility.
- [ ] Implement correction-only reverse with required reason and audit metadata; preserve historical process fields.
- [ ] Keep stage service free of duplicated branch/team scope logic; use policy at request/controller boundary.
- [ ] Run focused stage tests and commit `feat: add audited lead stage v3 transitions`.

### Task 3: Gugur and configurable reasons

**Files:**
- Modify: lifecycle request/controller boundary for Gugur payload.
- Modify: `app/Services/SalesLeadStageV3Service.php`.
- Modify: `app/Models/SalesLead.php` activity description if needed.
- Test: `tests/Feature/SalesLeadGugurTest.php`.

**Interfaces:**
- Gugur accepts `reason` only from `AppSettingResolver::list('sales_lead.gugur_reasons')`.
- Gugur is idempotent by operation UUID and allowed only before UTJ.

- [ ] Write failing BR-13 tests for configured reason, unknown reason, missing reason, duplicate operation, and post-UTJ rejection.
- [ ] Run focused tests and observe RED.
- [ ] Implement validation and service mutation with `gugur_at`, `gugur_reason`, `lead_stage=gugur`, legacy `current_status` compatibility, and audit.
- [ ] Ensure Gugur cannot be reverted through ordinary stage or lifecycle status endpoints.
- [ ] Run focused Gugur tests and commit `feat: add configurable lead gugur flow`.

### Task 4: Sales lock and UI

**Files:**
- Modify: `app/Policies/SalesLeadPolicy.php`.
- Modify: existing Lead lifecycle/detail/list Blade views.
- Modify: request/controller response payloads.
- Test: `tests/Feature/SalesLeadStageAuthorizationTest.php`, relevant render tests.

**Interfaces:**
- `SalesLeadPolicy::updateStageV3(User $user, SalesLead $lead): bool` follows existing `update()` scope plus BR-14 stage lock.
- UI consumes `lead_stage`, `lead_stage_changed_at`, `gugur_reason`; it renders no Berkas/Akad stage controls.

- [ ] Write failing BR-14 tests for Sales before UTJ, Sales after UTJ, Sales on Gugur, direct endpoint, supplemental role non-escalation, and out-of-scope Lead.
- [ ] Run focused authorization tests and observe RED.
- [ ] Implement policy/service lock using configured max edit stage, preserving manager/coordinator scope policy.
- [ ] Update Lead UI with five-stage progress, Gugur action/modal, inline errors, locked Sales state, and Berkas/Akad as facts only.
- [ ] Run focused authorization/render tests, `php artisan optimize:clear`, `php artisan view:cache`, and `npm run build` if markup changes.
- [ ] Commit `feat: enforce lead stage v3 sales lock and UI`.

### Task 5: Spreadsheet compatibility and changelog

**Files:**
- Modify: `app/Services/SalesLeadSpreadsheetContract.php`.
- Modify: lifecycle sync parser/writer only where canonical Gugur/UTJ mapping requires it.
- Create: idempotent WP-1.6 changelog migration.
- Modify: `docs/product/PRD_V3_IMPLEMENTATION_LOG.md`.
- Test: `tests/Feature/SalesLeadStageSpreadsheetCompatibilityTest.php` and existing bridge/sync tests.

**Interfaces:**
- Legacy Sheet status values remain accepted.
- Canonical v3 stage maps to Sheet `UTJ` or `Gugur`; stable `external_sync_id` remains identity.

- [ ] Write failing BR-10/BR-13 sync tests for UTJ/Gugur pull and push, branch isolation, and unchanged sync identity.
- [ ] Run focused sync tests and observe RED.
- [ ] Implement minimal contract/parser/writer compatibility; do not add cross-branch fallback or row-number identity.
- [ ] Add one idempotent Indonesian changelog migration and update implementation log with delivered scope, tests, and no-cutover boundary.
- [ ] Run focused sync tests, Pint, diff check, and `graphify update .` when available.
- [ ] Commit `feat: preserve lead stage v3 spreadsheet compatibility`.

### Task 6: Full verification and handoff

- [ ] Run all WP-1.6 focused tests.
- [ ] Run `composer test`.
- [ ] Run `vendor/bin/pint --dirty --test`, `git diff --check`, `php artisan optimize:clear`, `php artisan view:cache`, and `npm run build` when frontend changed.
- [ ] Run fresh SQLite migration verification.
- [ ] Review diff for secrets, PII, hardcoded URLs, scope bypass, and forbidden browser confirmation.
- [ ] Commit any verification-only documentation adjustment separately if required.
