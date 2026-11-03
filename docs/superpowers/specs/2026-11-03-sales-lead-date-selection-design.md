# Sales Lead Date Selection — Design Note

## Intent

Allow Sales users to record a lead using the actual lead date instead of
forcing every quick-create lead to today's date.

## Goals

- Replace the read-only mobile date context with a native `input[type=date]`.
- Keep today as the default value.
- Submit and persist the selected date.
- Resolve and validate the Sales project assignment against the selected date.
- Preserve routes, permissions, field names, native browser behavior, and the
  existing quick-create workflow.

## Non-goals and constraints

- No new date-picker dependency.
- No artificial `min` or `max` date restriction; existing valid-date rules stay
  authoritative.
- Do not change project selection UI or lead status behavior.
- Keep the existing date format (`Y-m-d`) and Indonesian labels.

## Chosen approach

Use the native date input already used by the non-Sales lead form. For primary
Sales submissions, stop overwriting a supplied `lead_date` in
`SalesLeadRequest::prepareForValidation()`. Use the selected date when resolving
the assigned project and when checking project assignment validity; fall back to
today only when the field is absent.

## Verification

Add source/render contracts for the native date input and feature coverage that
posts a non-today date and verifies the persisted lead date. Verify the mobile
form in a real browser and run the focused and full suites.
