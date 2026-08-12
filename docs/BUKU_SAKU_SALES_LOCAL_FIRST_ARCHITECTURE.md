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

Supervisor Monitoring Tim shows Kategori Aktivitas as read-only in Sales Agenda detail and includes it as a read-only Sales Agenda export column. This field does not grant editing, synchronization, or broader record visibility. Detail and export queries must preserve the same team and organization-scope rules defined in Section 9.

Creating, updating, completing, or rescheduling a Sales Agenda must not require Google availability. No Sales Agenda write is mirrored to `lead`, `data_sales`, or another Google tab.

Sales Agenda may start or support a later lead workflow, but agenda identity and lead identity remain separate. Any conversion must be explicit and must create or link the local lead under normal policy and validation rules. These field additions do not change record ownership, Sales Agenda subtype rules, role permissions, coordinator relationships, or organization scope.

## 3. Coordinator Final Structure

Coordinator uses one scoped Buku Saku Sales workspace with three distinct responsibilities:

1. **Lead Master** is the operational local lead workspace. Coordinator may create, review, edit, assign, export, and request delivery of leads only for authorized branches and Sales members in the coordinator's current team.
2. **Monitoring Mingguan** is the read-only team performance view. Its default metrics use local lifecycle history as defined in Section 7.
3. **Agenda Sales Tim** is the scoped read-only team agenda view defined in Section 8. It does not grant agenda mutation rights.

Coordinator Lead is an OASIS-local handoff flow. Coordinator input, assignment, review, and transfer to Sales use local database records and local authorization.

Required behavior:

1. Coordinator creates or receives lead data in OASIS.
2. OASIS validates branch, coordinator scope, selected Sales assignment, and resolved primary project.
3. OASIS stores the lead and assignment locally.
4. Local transaction establishes durable lead ownership and history.
5. OASIS marks the local projection for explicit push to the configured branch Google `lead` sheet.
6. Google failure is reported as delivery failure; Google must not become ownership truth or silently reassign the local lead.

Coordinator monitoring reads OASIS data only. Names, NIK values, spreadsheet rows, or `sales_pic` text must not create coordinator-to-Sales relationships at runtime. Lead Master, Monitoring Mingguan, and Agenda Sales Tim must apply the same coordinator-team and organization-scope intersection; selecting an inaccessible branch, project, Sales user, lead, or agenda is denied rather than silently replaced.

## 4. Local Canonical Lead Classification

Source, Channel, and Activity are OASIS-owned local canonical masters. Forms, filters, exports, validation, reporting, and push projections use these exact case-sensitive values. Google dropdowns or sheet contents must not define, add, rename, reorder, or remove local options at runtime.

Exact **Source** values:

- `Online`;
- `Offline`;
- `Referral`;
- `Freelance`;
- `Lead Cabang`.

Exact **Channel** values:

- `TikTok`;
- `Facebook`;
- `Instagram`;
- `WhatsApp`;
- `Website`;
- `Marketplace`;
- `Canvassing`;
- `Pameran`;
- `Event`;
- `Telepon`;
- `Datang Langsung`;
- `Tidak Diketahui`.

Exact **Activity** values:

- `Live`;
- `Konten Organik`;
- `Story`;
- `Reel`;
- `Iklan Berbayar`;
- `Marketplace`;
- `Broadcast WhatsApp`;
- `Follow Up Data Lama`;
- `Sebar Brosur`;
- `Pameran`;
- `Event`;
- `Referral`;
- `Freelance`;
- `Datang Langsung`;
- `Tidak Diketahui`.

Historical noncanonical values remain readable for audit and migration safety but are unavailable for new input. Normal request handling must not rewrite historical values implicitly.

## 5. Promo Local Master

Promo is an OASIS-owned local master, not a Google-derived dropdown. Lead input stores a local promo relationship or stable local promo identity and a durable display snapshot. Promo availability follows active state plus applicable branch/project scope; inactive historical Promo remains readable but unavailable for new selection.

The physical Google `lead` projection uses `nama_promo`. It carries the exact OASIS Promo display name captured for delivery; Google `id_promo`, helper lists, formulas, or workbook lookups are not local identity and must not be read to resolve Promo. `nama_promo` is a physical delivery column, while OASIS local Promo identity remains authoritative.

## 6. Final Team Semantics

Team membership answers **who** belongs below a viewer. Record scope answers **where** that viewer may see or act. These are separate checks and neither one expands the other.

Management hierarchy uses `users.supervisor_user_id`:

- Manager or Branch Manager may supervise an SPV;
- SPV may supervise a Coordinator;
- direct Manager or Branch Manager to Coordinator assignment is valid and does not require an intermediate SPV;
- this hierarchy must not be inferred from names, spreadsheet values, branch membership, project assignment, or role alone.

Coordinator-to-Sales membership uses only the current `sales_coordinator_sales` relationship. Sales users do not need `users.supervisor_user_id` to point to a Coordinator, SPV, Manager, or Branch Manager. `users.supervisor_user_id` must not be used as a fallback for Coordinator-to-Sales membership, and historical coordinator relationships must not grant current team access.

Both relationship families use OASIS user IDs, database constraints, active-account checks, and policy enforcement. Google `data_sales`, display names, spreadsheet names, and NIK values have no runtime role.

Every team query applies two independent stages:

1. Resolve **team WHO** from the current reporting episode: Manager or Branch Manager descendants through `users.supervisor_user_id`, including a directly assigned Coordinator; SPV descendants through `users.supervisor_user_id`; and each included Coordinator's current `sales_coordinator_sales` members.
2. Intersect records with **record WHERE** from `OrganizationScopeService` and `WorkspaceAccessService`, including applicable branch, project, assignment-window, permission, and workspace rights.

A person in the team does not make all of that person's records visible. A record in an accessible branch or project does not make its owner a team member. Explicit inaccessible people, branches, projects, or records are denied rather than replaced with a fallback.

Current monitoring uses the current episode only: current active reporting edges, current Coordinator-to-Sales edges, and applicable active account and assignment windows. Historical episodes remain available only for authorized historical reporting or audit and must preserve the relationships effective during the selected period; they must not grant current workspace access.

Duplicate paths are normalized deliberately. A Sales user reached through multiple Coordinators appears once in global team totals, people lists, and record result sets by user or record ID. Per-Coordinator breakdowns preserve one edge for each current Coordinator-to-Sales relationship, so the same Sales user may appear once under each applicable Coordinator. Aggregation must state whether it is global-deduplicated or edge-based and must not multiply lead, agenda, or lifecycle counts because the same person is reachable through multiple paths.

## 7. Weekly Metrics from First Lifecycle Transitions

Default weekly lead metrics are event metrics sourced from local lifecycle history. Each metric counts a lead only when its first qualifying transition into that lifecycle state occurred inside the selected week. Current status, repeated transitions, `updated_at`, legacy stage timestamps, Google row values, and push timestamps must not substitute for the first lifecycle transition.

The same lead may count once in multiple metrics when its first transitions into different lifecycle states occur during the selected week. It must not count twice in one metric after retries, repeated events, or later re-entry. Week boundaries use the application's agreed business timezone and date range, and all queries retain coordinator-team plus organization scope.

## 8. Agenda Sales Tim

Agenda Sales Tim is a Coordinator-scoped read-only view of Sales Agenda records owned by current Sales members in the coordinator's authorized team and organization scope. It may provide list, detail, filters, and read-only reporting needed for monitoring, but it grants no create, edit, complete, reschedule, delete, assignment, comment mutation, export, synchronization, or broader visibility rights unless a separate existing permission and policy explicitly grants that operation.

`Tatap Muka Konsumen` remains an exact Sales Agenda Kategori Aktivitas. It describes planned or completed agenda work and remains attached to Sales Agenda identity.

**Lead Tatap Muka** is a distinct lead lifecycle event. It records the first qualifying face-to-face lead transition used by lifecycle history and weekly metrics. It is not an alias for Agenda `Tatap Muka Konsumen`, must not be inferred merely because such an agenda exists or is completed, and must not create, complete, or mutate an agenda automatically. Any explicit link between a lead and agenda preserves both identities, histories, policies, and scopes.

## 9. Supervisor Monitoring Tim

Supervisor Monitoring Tim is a primary read-only branch of the Buku Saku Sales flow. It uses the same shared OASIS application URL and provides:

- team KPI summaries;
- attention items requiring supervisor review;
- coordinator and Sales monitoring;
- Sales Agenda exports;
- lead exports.

Visible team membership follows Section 6: management levels use current `users.supervisor_user_id` edges, while Coordinator-to-Sales membership uses only current `sales_coordinator_sales` edges. Every result is intersected with `OrganizationScopeService` record scope and `WorkspaceAccessService` workspace rights. Names, spreadsheet values, branch/project coincidence, Sales supervisor fields, and historical relationships must not expand current scope.

Role workspace outcomes are:

- Manager and Branch Manager: read-only monitoring for authorized organization scope across current SPV and directly or indirectly supervised Coordinator episodes, plus those Coordinators' current Sales edges;
- SPV: read-only monitoring for authorized organization scope across current directly supervised Coordinators and those Coordinators' current Sales edges;
- Coordinator: scoped Lead Master operations plus read-only Monitoring Mingguan and Agenda Sales Tim for current `sales_coordinator_sales` members, always within authorized organization scope and workspace rights;
- Sales: own-only Buku Saku Sales workspace and own records within authorized branch/project assignments; team edges, shared branch/project access, or a populated `supervisor_user_id` do not grant another Sales user's records.

Monitoring branches permit no synchronization, editing, or operational input unless a separate existing permission and policy explicitly grants that operation. All monitoring and exports read OASIS data only. Google and `data_sales` have no runtime role in KPI calculation, attention items, hierarchy resolution, monitoring, or exports.

Google Login remains Phase 2. Its shared application URL and authentication boundary are unchanged by Supervisor Monitoring Tim.

## 10. Primary Project Resolution

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

## 11. Google Lead Push-Only Contract

Only the branch Google `lead` tab participates as a write-only delivery target. Workbook resolution follows the lead's OASIS branch and that branch's configured spreadsheet ID. No fallback to another branch or shared reference workbook is allowed.

OASIS pushes a validated projection after local ownership and project resolution succeed. Stable OASIS identity and idempotency metadata must be used so retries do not create duplicate rows. Remote row numbers are location metadata, not durable identity. Delivery is local-first and push-only: normal create and update transactions commit without Google availability, then explicit or controlled delivery handles pending projections and retry state.

No runtime Google reads are permitted for this architecture. OASIS must not read workbook headers, formulas, dropdowns, helper lists, rows, metadata, Promo, Source, Channel, Activity, project names, Sales names, statuses, or existing lead values to validate, enrich, authorize, calculate, reconcile, or display local workflows. Push code may use only local configuration and the fixed physical delivery contract.

This architecture does not use runtime pull synchronization to create, update, assign, or reconcile local leads from Google. Google edits do not override OASIS lead ownership, coordinator assignment, project assignment, Promo, classification, workflow history, or weekly metrics.

`data_sales` is excluded from runtime reads, writes, validation, capability checks, reconciliation, and identity resolution. Historical spreadsheet data may be handled only by an explicit, offline migration or audit process with separate validation and authorization.

## 12. Google Login Phase 2

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

## 13. Failure and Security Boundaries

Local authorization and validation run before any Google push. Explicit inaccessible branch, project, coordinator, or Sales IDs are denied rather than replaced with a fallback.

A failed Google push must expose a retryable delivery state without weakening local identity or creating another local lead. Retries must be idempotent. Logs, notifications, and URLs must not expose OAuth credentials, Google access tokens, invitation tokens, reset tokens, or hidden lead data.

No runtime behavior may depend on Google reads, `data_sales`, spreadsheet names as user identity, arbitrary project text, remote option lists, or token-bearing links.
