# OASIS Buku Saku Sales Local-First Architecture

This document defines the intended architecture for Sales Agenda, Coordinator Lead, lead spreadsheet delivery, coordinator assignments, project resolution, and Phase 2 Google Login. OASIS application code, database constraints, routes, policies, and migrations remain authoritative for implemented behavior. This document does not claim that every target described below is already implemented.

## 1. Architecture Boundary

OASIS is the operational source of truth for this flow. Local database records own identity, authorization, organization scope, workflow state, history, and relationships.

Google Sheets is not a second application database. The branch `lead` sheet is a push-only delivery target for lead data required outside OASIS. Runtime reads from Google must not decide Sales Agenda state, Coordinator Lead state, coordinator assignments, project ownership, authorization, or login identity.

The flow is intentionally split:

- Sales Agenda is local-only;
- Coordinator Lead is local-only;
- accepted lead data is stored in OASIS first;
- OASIS pushes the required lead projection to the branch Google `lead` sheet;
- OASIS does not pull lead ownership or workflow state back from Google for this architecture;
- `data_sales` has no runtime role.

## 2. Sales Agenda Local Flow

Sales Agenda remains an OASIS workflow backed by local records. OASIS owns:

- agenda identity;
- Sales owner;
- branch and project context;
- scheduled date and agenda details;
- required Kategori Aktivitas (`sales_activity_category`);
- optional Hasil (`activity_result`);
- status, reschedule linkage, and optimistic lock state;
- organization scope, authorization, comments, notifications, and audit history where supported.

Kategori Aktivitas is required for new Sales Agenda input. Its exact canonical options are:

- `Canvassing`;
- `Follow-up`;
- `Telepon/WhatsApp`;
- `Tatap Muka Konsumen`;
- `Cek Lokasi`;
- `TikTok Live`;
- `Pembuatan Konten`;
- `Event/Pameran`;
- `Administrasi`;
- `Rapat`;
- `Lainnya`.

`Survey Lokasi` is a historical value. Existing records containing `Survey Lokasi` remain readable, but that value is unavailable for new input. Existing records with a null Kategori Aktivitas also remain valid and readable; the new required rule must not invalidate, hide, or rewrite them. Hasil remains optional.

Supervisor Monitoring Tim shows Kategori Aktivitas as read-only in Sales Agenda detail and includes it as a read-only Sales Agenda export column. This field does not grant editing, synchronization, or broader record visibility. Detail and export queries must preserve the same team and organization-scope rules defined in Section 5.

Creating, updating, completing, or rescheduling a Sales Agenda must not require Google availability. No Sales Agenda write is mirrored to `lead`, `data_sales`, or another Google tab.

Sales Agenda may start or support a later lead workflow, but agenda identity and lead identity remain separate. Any conversion must be explicit and must create or link the local lead under normal policy and validation rules. These field additions do not change record ownership, Sales Agenda subtype rules, role permissions, coordinator relationships, or organization scope.

## 3. Coordinator Lead Local Flow

Coordinator Lead is an OASIS-local handoff flow. Coordinator input, assignment, review, and transfer to Sales use local database records and local authorization.

Required behavior:

1. Coordinator creates or receives lead data in OASIS.
2. OASIS validates branch, coordinator scope, selected Sales assignment, and resolved primary project.
3. OASIS stores the lead and assignment locally.
4. Local transaction establishes durable lead ownership and history.
5. OASIS pushes the required lead projection to the configured branch Google `lead` sheet.
6. Google failure is reported as delivery failure; Google must not become ownership truth or silently reassign the local lead.

Coordinator monitoring reads OASIS data only. Names, NIK values, spreadsheet rows, or `sales_pic` text must not create coordinator-to-Sales relationships at runtime.

## 4. Coordinator-to-Sales Assignment

Coordinator-to-Sales membership uses a dedicated database pivot between OASIS users. The pivot is the source of truth for which Sales users a coordinator may manage or receive in assignment controls.

The relationship must use user IDs, database constraints, active-account checks, organization scope, and policy enforcement. Display names, spreadsheet names, NIK values, and reporting-hierarchy inference are not durable relationship keys.

Exact table name, columns, indexes, lifecycle fields, and migration behavior must be defined by the implementing migration and models. Do not reuse Google `data_sales` as this pivot and do not infer the relationship during requests.

## 5. Supervisor Monitoring Tim

Supervisor Monitoring Tim is a primary read-only branch of the Buku Saku Sales flow. It uses the same shared OASIS application URL and provides:

- team KPI summaries;
- attention items requiring supervisor review;
- coordinator and Sales monitoring;
- Sales Agenda exports;
- lead exports.

Visible team membership is resolved from `supervisor_user_id` first, then from the current `sales_coordinator_sales` relationship, and is always intersected with the supervisor's authorized organization scope. Names, spreadsheet values, and historical relationships must not expand this scope.

This branch permits no synchronization, editing, or operational input. All monitoring and exports read OASIS data only. Google and `data_sales` have no runtime role in KPI calculation, attention items, hierarchy resolution, monitoring, or exports.

Google Login remains Phase 2. Its shared application URL and authentication boundary are unchanged by Supervisor Monitoring Tim.

## 6. Primary Project Resolution

Lead delivery requires one unambiguous active project for the selected Sales user in the applicable branch and assignment window.

Resolution order is exact:

1. Use the Sales user's active assignment marked primary when exactly one valid active primary assignment exists.
2. If no valid active primary exists, use the project only when exactly one valid active project assignment remains.
3. Otherwise block the operation and require assignment correction or explicit supported selection.

Blocked cases include:

- multiple valid active primary assignments;
- no valid active assignment;
- multiple active assignments without one valid primary;
- inactive project or branch;
- assignment outside its active date window;
- project outside the coordinator's or Sales user's authorized scope.

Resolution must not choose the first database row, newest row, alphabetically first project, project text from Google, or another branch's project.

## 7. Google Lead Push-Only Contract

Only the branch Google `lead` tab participates at runtime. Workbook resolution follows the lead's OASIS branch and that branch's configured spreadsheet ID. No fallback to another branch or shared reference workbook is allowed.

OASIS pushes a validated projection after local ownership and project resolution succeed. Stable OASIS identity and idempotency metadata must be used so retries do not create duplicate rows. Remote row numbers are location metadata, not durable identity.

This architecture does not use runtime pull synchronization to create, update, assign, or reconcile local leads from Google. Google edits do not override OASIS lead ownership, coordinator assignment, project assignment, or workflow history.

`data_sales` is excluded from runtime reads, writes, validation, capability checks, reconciliation, and identity resolution. Historical spreadsheet data may be handled only by an explicit, offline migration or audit process with separate validation and authorization.

## 8. Google Login Phase 2

Google Login is Phase 2 and uses Laravel Socialite. It is an authentication convenience, not account provisioning or invitation activation.

Rules:

- accept Google authentication only when Google returns a verified email;
- match only an existing OASIS user by normalized email;
- allow login only for an existing active account that satisfies current OASIS lifecycle requirements;
- do not create users automatically;
- do not activate pending, invited, suspended, inactive, anonymized, or otherwise ineligible accounts;
- do not grant roles, permissions, branches, projects, coordinator assignments, or Sales assignments from Google claims;
- preserve existing OASIS session regeneration, account checks, audit behavior, and redirect safety;
- use the intended shared application callback URL configured for the deployed OASIS environment;
- do not send login tokens, invitation tokens, reset tokens, or bearer credentials in links.

The OAuth callback must use Socialite state validation and server-side configuration. Account recovery, invitation activation, and password reset remain separate OASIS flows.

## 9. Failure and Security Boundaries

Local authorization and validation run before any Google push. Explicit inaccessible branch, project, coordinator, or Sales IDs are denied rather than replaced with a fallback.

A failed Google push must expose a retryable delivery state without weakening local identity or creating another local lead. Retries must be idempotent. Logs, notifications, and URLs must not expose OAuth credentials, Google access tokens, invitation tokens, reset tokens, or hidden lead data.

No runtime behavior may depend on `data_sales`, spreadsheet names as user identity, arbitrary project text, or token-bearing links.
