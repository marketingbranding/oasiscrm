# Mobile Lead Modal Actions — Design Note

## Intent

Keep the mobile Tambah lead sheet usable at 425px and smaller heights by making
the existing save actions persistently reachable and adding an explicit close
control.

## Goals

- Add an accessible `Tutup` button to the mobile entry heading.
- Keep `Simpan` and `Simpan & Tambah Lagi` visible in a sticky bottom action area.
- Preserve the existing form action, field names, validation, Alpine submit state,
  and desktop layout.
- Keep the fields independently scrollable when content or validation messages
  exceed the sheet height.

## Non-goals and constraints

- No route, controller, authorization, validation, or dependency changes.
- Do not replace the native `<details>` open/close behavior.
- Do not change the existing button labels or submit values.
- Maintain keyboard access, visible focus, safe-area padding, and the current
  OASIS mobile visual language.

## Chosen approach

Use a header close button that closes the parent `<details>` and a sticky action
footer containing the two existing submit buttons. The form body remains the
scroll container; the footer stays visible within the mobile sheet. Desktop
styles remain unchanged through the existing mobile-only rules.

## Verification

Add source-contract coverage for the close control, both submit values, and the
sticky action styling. Verify the rendered mobile sheet at approximately
425×812, including close behavior, button visibility, focus state, no horizontal
overflow, and a desktop regression check.
