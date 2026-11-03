# Sales Agenda Date Navigation Bug-Fix Plan

**Goal:** Make the Sales Agenda date choices consistently navigate to the selected date and remain available when that date already contains agenda records.

**Scope:** Local code only. Preserve the existing receipt workflow, date query contract, agenda storage, permissions, and break-aware scheduling behavior.

## Expected behavior

- `Kemarin` links to yesterday through `work_date`.
- `Hari ini` links to today through `work_date`.
- `Rencana` links to the next planned day represented by the current UI contract.
- The choices remain visible when the selected date has existing agendas.
- The selected choice has an accessible current-state marker.
- The selected date's agenda list remains the only list shown on that page.

## Tasks

### Task 1: Reproduce the populated-date navigation failure

- Add a feature test to `tests/Feature/SalesAgendaWorkspaceRenderTest.php` with an agenda on the selected date.
- Assert the three navigation labels and their exact `work_date` links are rendered.
- Run the focused test and confirm it fails before implementation because the current receipt mode hides the navigation.

### Task 2: Render date navigation independently from receipt/empty state

- Update `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php` so date navigation is rendered once before the receipt/empty-state branch.
- Add `aria-current="page"` to the active date link.
- Keep existing agenda filtering and receipt behavior unchanged.
- Run the focused feature tests and confirm the regression passes.

### Task 3: Verify the browser contract

- Clear/cache views as needed and run focused PHP tests, Pint for changed PHP files, Vite build if frontend-scanned markup requires it, and `git diff --check`.
- Reuse the existing local preview and inspect the agenda with populated data at approximately 390×844 and 1440×900.
- Confirm navigation is visible, links change `work_date`, selected-date agendas remain isolated, and the console is clean.

## Review focus

- Do not reintroduce the old all-groups-on-one-page behavior.
- Do not change production or mutate production data.
- Do not change agenda schemas, routes, permissions, or storage semantics.
