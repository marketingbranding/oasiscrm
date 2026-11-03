# Sales Lead Detail — Mobile Redesign Design

## Intent

Redesign the Sales lead detail screen to match the supplied 390×844 mobile
reference: a polished, compact operational view rather than the current generic
detail form. The reference image is a visual target; real lead data, lifecycle
semantics, permissions, and existing workflows remain authoritative.

## Goals

- Reproduce the reference's hierarchy: back link, lead name and project context,
  current-state badge, five-stage progress strip, information panel, result
  history panel, and fixed bottom actions.
- Keep OASIS's cream background, black structural rules, yellow active state,
  square geometry, and compact typography.
- Make status and result history reflect persisted lead lifecycle data.
- Keep primary actions reachable at the bottom on mobile, with safe-area padding.
- Preserve the current desktop layout and all routes, authorization, and write
  behavior.

## Non-goals

- No new route, permission, database field, dependency, or lifecycle status.
- No free-form follow-up note workflow. Existing status history has status and
  timestamp, not arbitrary notes; show only real status/visit information.
- No change to how lead completion, UTJ, Cek Lokasi, or manual status changes
  are persisted.
- No redesign of the lead list, edit page, or agenda detail page.

## Approaches

1. **Dedicated mobile composition in the existing detail view (selected):**
   retain the current desktop composition and use a mobile-specific presentation
   for the same authorized lead data. This follows the existing
   `sales-mobile-detail-page` styling contract and confines the redesign to the
   reported screen.
2. **Replace the shared desktop/mobile composition:** rejected because the
   request is based on a mobile reference and desktop users should not receive an
   unrequested redesign.
3. **Absolute-positioned screenshot imitation:** rejected because names,
   history, and status labels vary and the result would fail on real content,
   accessibility, and other mobile widths.

## Proposed screen contract

At the reference viewport (390×844 CSS pixels):

1. A compact “‹ Lead Saya” back link returns to the Leads view.
2. The lead header shows the real customer name, `project · branch` context, and
   a text badge for `Belum selesai` or `Selesai`.
3. A five-column progress strip reads `No Respon`, `Tatap Muka`, `Cek Lokasi`,
   `Cek SLIK`, `Selesai`. It is a visual summary, not a replacement state
   machine. Map existing states without mutating them: `no_response` → step 1;
   `discussion`/`face_to_face` → step 2; `site_visit`/`utj` → step 3;
   `slik_check`/`slik_rejected` → step 4; `akad` or `completed_at` → final
   state. `freelance` remains an independent conversion and must not move the
   primary progress backwards or overwrite it. The current status remains
   explicitly available as text for clarity.
4. “Informasi” is a bordered white panel showing phone, effective source, and
   lead date, matching the reference's content priority. Other current details
   (Sales, branch/project, notes) remain available in a compact secondary
   portion of the same detail content; they are not discarded.
5. “Riwayat hasil” is a bordered panel whose entries come from persisted status
   histories and site visits, newest first. Show status and timestamp; include
   visit outcome/notes only when an actual visit provides them. Do not invent
   history counts or explanatory copy when values are absent.
6. A fixed bottom action bar provides “Update hasil” and “Tandai selesai”.
   “Update hasil” uses existing manual-status confirmation forms and the
   separate UTJ action when authorized; those forms continue to record
   timestamps automatically and preserve existing service behavior. “Tandai
   selesai” uses the existing completion route and is rendered only when the
   current completion policy allows it. Completed leads show their completion
   state instead of an enabled completion action. Existing Cek Lokasi result
   entry/edit actions remain available when their policy and data conditions
   allow them.

## Responsive and accessibility contract

- Tune visual matching at 390×844; also verify 360px, 430px, 768px, 1024px,
  and desktop 1366px.
- No page-level horizontal overflow. The five progress labels may wrap at the
  narrowest supported width without clipping or hiding the current state.
- Sticky actions use `env(safe-area-inset-bottom)`, have at least 44px targets,
  and reserve content space so the history is not obscured.
- Keep semantic headings, links, buttons, visible text labels, focus states,
  keyboard operation, and non-color status cues.
- Respect reduced motion; this design does not require new animation.

## Constraints and risks

- `SalesLeadStatus` contains additional values (`discussion`, `utj`,
  `slik_rejected`, `akad`, and independent `freelance`) beyond the five labels
  in the reference. The visual mapping is display-only; canonical status and
  lifecycle rules remain unchanged.
- `sales_lead_status_histories` stores status and timestamp, not free-form
  follow-up commentary. The history panel must not imply notes exist when they
  do not.
- The current view uses separate permissions for viewing, editing status,
  recording visits, deleting, and completing. Each action must remain guarded
  by the existing policy rather than being made available because it appears in
  the reference.
- User-visible UI changes require an idempotent Oasis Changelog migration and
  test under the repository's engineering standards.

## Verification expectations

- Focused feature tests cover rendered sections/status mapping, history ordering,
  action visibility by permission, and route/authorization behavior.
- Browser verification compares the mobile render against the supplied
  reference, checks sticky actions, scrolling, focus/keyboard use, console, and
  overflow; desktop remains behaviorally and visually unchanged.
- Run focused tests, view cache, Vite build, Pint for changed PHP, and
  `git diff --check`.
