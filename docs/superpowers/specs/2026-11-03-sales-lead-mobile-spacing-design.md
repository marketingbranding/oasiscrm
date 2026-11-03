# Sales Lead Mobile Spacing — Design Note

## Intent

Improve the mobile quick-create form at `/buku-saku-sales/agendas/workspace`.

## Goals

- Remove the helper text `(opsional untuk input cepat)` from the optional-fields summary.
- Add local vertical separation so the Tanggal Lead field does not visually touch the form header.
- Preserve the existing form fields, behavior, labels, and responsive desktop layout.

## Approaches

- **Local form adjustment (selected):** remove the helper span and adjust the
  Lead Saya mobile form spacing in its existing Blade/CSS contract. This limits
  the visual change to the reported screen.
- **Global spacing primitive:** change shared CRM spacing tokens. Rejected because
  it could shift unrelated CRM forms.

## Constraints

- No route, backend, dependency, or form workflow changes.
- Keep OASIS compact workstation styling and existing 4px spacing rhythm.
- Verify at approximately 425×812 and ensure no horizontal overflow.
