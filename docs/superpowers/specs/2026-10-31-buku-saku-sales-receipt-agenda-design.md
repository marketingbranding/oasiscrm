# Buku Saku Sales Receipt Agenda Redesign

**Status:** Approved implementation scope (expanded by 2026-10-31 rework brief)
**Date:** 2026-10-31
**Scope:** Local development only; no production deployment is included in this design.

## Intent

Rework the primary Sales user's Buku Saku Saya experience so that the two personal
workspaces—**Agenda** and **Lead Saya**—match the supplied mobile references. The
Agenda workspace becomes a timed, receipt-like daily work surface. Lead Saya becomes
a compact card list with the same visual language, without mixing lead data into the
Agenda timeline.

The expanded rework brief is now the visual and workflow authority for the Sales
role: the personal workspace is mobile-first at 390px, uses warm brutalist paper
surfaces, and replaces the legacy onboarding screen with a five-step in-context
tour over the real Agenda/Lead Saya UI.

The redesign is visual and workflow-focused, but the agreed Agenda submission,
deferral, and extra-agenda behavior requires a small, explicit persistence layer. The
existing authorization, ownership, evidence, and lead business rules remain the
source of truth.

## Goals

- Match the supplied references closely on mobile: cream canvas, strong black
  structure, OASIS yellow actions, compact bold typography, bordered receipt/list
  surfaces, and sticky bottom actions.
- Keep exactly two primary personal tabs: **Agenda** and **Lead Saya**.
- Display multiple timed Sales Agendas as one chronological daily timeline.
- Require a start time and end time for newly created Sales Agendas, while keeping
  legacy untimed records readable as `Waktu fleksibel`.
- Show a fixed daily target of **420 minutes** and calculate normal scheduled progress
  from agenda durations.
- Support the reference states: empty day, partially filled day, complete 420-minute
  day, incomplete previous-day warning, submitted day, and additional work outside
  the submitted schedule.
- Provide a mobile bottom-sheet form for adding an Agenda.
- Keep Lead Saya as a separate card-list workflow with open/completed counts and a
  sticky `+ Tambah lead` action.
- Preserve named routes, existing authorization, organization scope, evidence uploads,
  optimistic locking, lead validation, pagination, and export behavior unless an
  explicit design decision below changes the affected Agenda write path.

## Non-goals and Anti-goals

- Do not redesign coordinator, supervisor, manager, or administrative monitoring
  screens in this change.
- Do not merge `SalesLead` and `ContentItem` data models.
- Do not introduce a new frontend framework, date/time library, or UI library.
- Do not make personal Daily Plan records required for Sales users. Sales users work
  only with business Sales Agendas in this workspace.
- Do not silently change existing role, permission, branch, project, or ownership
  rules.
- Do not make the 420-minute target configurable in this first redesign.
- Do not add drag-and-drop scheduling, automatic conflict resolution, or calendar
  synchronization.
- Do not delete or rewrite legacy untimed agendas.
- Do not claim literal pixel identity at every viewport; the mobile references are the
  visual source of truth, while desktop and accessibility require deliberate
  responsive adaptation.

### Expanded rework contract

- The visual tokens for this route are `#141210` ink, `#FBF7EC` paper, `#FDC20F`
  yellow, `#5E594D` muted text, `#1F6B45` success, `#FFF1D6` warning, and
  `#FFFDF5` receipt paper. New route-specific controls use Archivo; receipt data
  uses Courier New. Existing global CRM modules are not restyled by this contract.
- The old illustrated onboarding page is no longer a user destination. The existing
  per-user progress record is reused at a new tour version so old completion state
  does not suppress the new tour. The tour is rendered over the real workspace and
  can be replayed from the help menu.
- Weekdays are Monday-Friday. Monday-Thursday use 09:00-17:00 with a 12:00-13:00
  break; Friday uses 08:30-17:00 with an 11:30-13:00 break. The effective target
  remains 420 minutes. Saturday/Sunday are free-plan days with no target, break row,
  or incomplete-empty warning; a weekend submission requires at least one Agenda
  and carries an `is_weekend` marker.
- Agenda schedule validation must reject overlap and break-time placement. The
  authoritative server rules remain in the receipt service; the browser only previews
  derived end times and remaining capacity.
- The empty Agenda state uses a work-hours card, progress meter, dashed empty card,
  previous-day warning when applicable, and a safe-area-aware sticky bottom action.
  A populated weekday uses a dark canvas with a toothed receipt, break row, empty
  slots, and tear/send action. A populated weekend receipt uses the `AKHIR PEKAN`
  label/stamp and total-minutes-without-target footer.
- Lead Saya remains a card-only mobile list with segmented open/completed counts and
  a bottom-sheet add flow. Existing lead routes, detail actions, completion semantics,
  and authorization are preserved.

## Current Evidence and Constraints

- Primary Sales Agenda route: `sales-agendas.index`, served at
  `/buku-saku-sales/agendas/workspace`.
- The current page is rendered by
  `resources/views/crm/sales-pocketbook/sales-agenda.blade.php` and the data is
  composed by `SalesAgendaController::index`.
- The current controller already provides `Kemarin`, `Hari ini`, and `Rencana`,
  where `Rencana` means all future-dated agendas for the owner.
- `ContentItem` already stores `start_time`, `end_time`, and `duration_minutes`.
- `StoreSalesAgendaRequest` validates time format and end-after-start, but currently
  permits both times to be absent. This redesign changes new Agenda creation to
  require both times; the persisted legacy data remains readable.
- Existing Agenda statuses are `planned`, `confirmed`, `done`, `cancelled`, and
  `rescheduled`.
- Existing Agenda evidence routes and authorization remain in force, including the
  camera/upload hotfix already deployed in the approved commit.
- Existing mobile Lead Saya cards, Lead status filtering, pagination, and lead-entry
  behavior are present and should be restyled rather than replaced with a second
  lead workflow.
- Existing OASIS CSS tokens, CRM components, Alpine registration, and the
  `sales-agenda-paper.js` module are the implementation foundation.
- New dialogs/bottom sheets must meet the repository's modal requirements: labelled
  dialog, `aria-modal`, initial focus, focus trap, Escape close, focus restoration,
  viewport-safe scrolling, and no outside-click-only close behavior.

## Approaches Considered

### Direct edit of the existing view

Replace the current mixed Blade markup and extend its existing CSS in place.

- Fastest initial implementation.
- Lowest file count.
- Rejected as the primary approach because Agenda, Lead Saya, forms, states, and
  responsive variants are already interleaved; the receipt and bottom-sheet states
  would make the view harder to review and maintain.

### Modular page rework — chosen

Keep the current route/controller contracts and split the presentation into focused
Blade partials and small, named UI state modules. Add only the minimal server-side
composition and persistence needed for the agreed submission/deferral behavior.

- Best balance between reference fidelity, maintainability, and safe rollout.
- Keeps authorization and business services in their current architecture.
- Makes mobile receipt, Lead cards, bottom sheet, empty states, and desktop
  adaptation independently testable.

### Parallel replacement screen

Build a second Buku Saku page beside the current one and switch routes later.

- Allows visual experimentation in isolation.
- Rejected because it duplicates route behavior and creates two competing personal
  workspaces. A local feature branch and focused browser verification provide enough
  safety without shipping duplicate production architecture.

## Chosen Design

### Presentation structure

The existing personal Sales route remains the entry point. Its page is reorganized
into these presentation units:

1. **Buku Saku header** — OASIS identity, user/project context, and the two-tab
   navigation.
2. **Agenda workspace** — date context, 420-minute summary, receipt/timeline,
   warning states, and sticky add action.
3. **Lead Saya workspace** — open/completed segmented counts, lead cards, empty state,
   pagination, and sticky add action.
4. **Agenda bottom sheet** — category chips, title, location, start time, duration
   choices, derived end time/remaining capacity, validation, and submit action.

The current desktop/tablet layout remains content-first and compact. At approximately
390px, the supplied mobile composition is authoritative: one column, full-width
bordered surfaces, 44px minimum controls, and a fixed bottom action area that never
covers scrollable content.

### Agenda visual states

The Agenda tab supports these states:

| State | Visible behavior |
|---|---|
| Empty today | 0/420 summary, empty receipt/workspace message, add Agenda action |
| Partially filled | `<minutes>/420`, remaining capacity message, chronological rows, add action |
| Exactly 420 | 420/420 summary, complete receipt, enabled `Sobek & kirim ke Koordinator` |
| Over capacity before submit | Prevent normal scheduled additions beyond remaining capacity; explain remaining minutes |
| Submitted | Submitted label/alias, planned fields read-only while completion/evidence remain available, no normal additions |
| Extra Agenda | Green visual treatment, clearly labelled as additional work, excluded from 420 total |
| Previous incomplete | Compact warning card on today showing unfinished previous-day work and a review link |
| Future plan | `Rencana` context with future-dated rows ordered by date and start time |
| Failure/denied | Shared CRM alert/toast/error behavior; no page-local notification system |

Normal rows show start-end time, duration, title, category, and a visual status alias.
Breaks/gaps are derived display rows where useful; they are not stored as fake
Agendas. A gap can be shown as `kosong` or an explicitly styled break without
changing business records.

### Status aliases

The database statuses and workflows remain stable. The Agenda UI uses these labels:

| Existing status/condition | Display alias |
|---|---|
| `planned` | `Menunggu` |
| `confirmed` | `Terkirim ke Koordinator` |
| `done` | `Selesai` |
| current time is inside a planned/confirmed row | `Berjalan` (derived, not persisted) |
| `cancelled` | `Dibatalkan` |
| `rescheduled` used for a carried-forward item | `Ditunda ke besok` |

Color never stands alone: every alias includes visible text and appropriate semantic
contrast. Green is reserved for completed/additional-work semantics and is not used
as a generic module accent.

### Agenda creation bottom sheet

The sticky `+ Tambah agenda` action opens a mobile bottom sheet. The selected day is
the form context; the form does not silently change the active date.

Fields and interactions:

- category chips using the existing Sales activity categories;
- required Agenda title;
- optional location;
- required start time using the existing CRM time-picker;
- required duration choice, initially matching the reference choices of 30, 60, 90,
  and 120 minutes;
- derived end time and remaining daily capacity shown before submission;
- server-side validation remains authoritative;
- the form submits through the existing named Agenda store route.

The browser may derive `end_time` from start plus duration for immediate feedback, but
the server calculates and stores the authoritative duration. A user cannot bypass
time or capacity rules by editing browser values.

### Daily target and submission

Normal Agenda duration is summed against a fixed 420-minute target. Additional
Agendas created after submission are explicitly marked outside the submitted schedule
and do not change the normal progress total.

At exactly 420 minutes, Sales can submit the day's Agenda receipt using
`Sobek & kirim ke Koordinator`. Submission means:

- the submitted day is recorded as sent;
- planned fields on submitted Agenda rows—title, category, location, start time, and
  scheduled duration—are no longer editable by the Sales owner;
- execution actions remain available: Sales may mark a submitted Agenda `Selesai`,
  record its result, and add permitted evidence photos;
- no new normal Agenda can be added to that submitted schedule;
- an additional Agenda may still be added outside the 420-minute schedule and is
  shown with the green additional-work treatment;
- the submitted receipt remains auditable and viewable by authorized coordinator/
  monitoring users.

This is not a destructive lock and does not prevent authorized monitoring or cleanup.
The implementation should use an explicit owner/date submission record rather than
infer a day state from one arbitrary Agenda row. The record should contain the owner,
scheduled date, submission timestamp, target minutes, and the normal Agenda total at
submission. Exact naming and migration shape are implementation details for the
approved plan.

### Deferral to the next day

For a submitted or completed workday, an unfinished Agenda can be deferred:

- preserve the original day and its audit/history;
- mark the original as carried forward using the existing reschedule relationship;
- create a new next-day Agenda copy linked to the original;
- keep the new copy editable only under the existing authorization rules;
- avoid duplicating evidence or falsely marking the original as completed.

The existing reschedule endpoint is currently a disabled stub, so implementing this
behavior is an explicit backend slice rather than a view-only change.

### Lead Saya visual treatment

Lead Saya remains a separate workflow. It follows the supplied reference:

- same Buku Saku header and two-tab navigation;
- segmented `Belum selesai · count` and `Selesai · count` controls;
- vertical bordered cards with lead name, project, date, phone, and status alias;
- status treatment using semantic text and color;
- sticky `+ Tambah lead` action;
- current lead creation fields, duplicate warning, authorization, pagination, and
  detail/edit links remain intact.

The mobile Lead entry interaction will be implemented as a focused bottom sheet when
the existing form is moved into the new shell; desktop may use the same sheet or an
inline presentation if that is required for keyboard and viewport usability. The
form's data contract does not change.

## Data and route implications

The following are expected implementation changes after approval:

- require times for newly created Sales Agendas and preserve legacy untimed rows;
- add an explicit extra/outside-schedule marker for post-submission Agendas;
- add an owner/date day-submission record to represent `Terkirim ke Koordinator`;
- implement the existing Agenda reschedule endpoint for linked next-day deferral;
- enforce submitted-day mutation rules in backend authorization, not only in the UI;
- keep existing evidence, lead, project, branch, and scope checks unchanged;
- keep named routes and existing route middleware unless a focused authorization test
  demonstrates a necessary correction.

No schema or route change should be implemented until the plan specifies migration
rollback behavior and authorization coverage.

## Verification contract

The implementation plan must include:

- request/controller tests for required time, duration calculation, 420-minute
  progress, submitted-day mutation denial, extra Agenda creation, and next-day
  deferral;
- authorization tests for Sales owner, unrelated Sales user, coordinator/monitoring
  viewer, denied user, and direct URL/write attempts;
- rendered markup/source-contract tests for both reference states and all key labels;
- browser verification at approximately 390px, 768px, 1024px, and 1440px;
- browser checks for bottom-sheet open/close, focus, Escape, validation errors,
  sticky actions, overflow, and evidence/detail links;
- build, focused PHPUnit tests, full tests where risk warrants, Pint for changed PHP,
  cache/view verification, and `git diff --check`.

## Approval gate

This document is a design proposal only. No application code, migration, route, or
production configuration should change until the user approves the design and any
remaining implementation calls—especially the exact day-submission persistence shape
and the Lead Saya add-form presentation—are accepted in the implementation plan.
