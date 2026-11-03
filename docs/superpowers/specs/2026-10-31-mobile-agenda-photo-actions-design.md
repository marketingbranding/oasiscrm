# Mobile Agenda Photo Actions

## Intent

Give mobile Sales users two clear ways to add Agenda evidence: choose an existing image or take a new photo with the device camera.

## Goals

- Show separate **Upload dari perangkat** and **Ambil foto** actions on the Agenda detail page.
- Keep both actions on the existing multipart upload endpoint and existing image validation/storage flow.
- Preserve the current maximum of two photos and the restriction that evidence cannot be added after completion.
- Keep desktop behavior usable; the camera action may fall back to the browser file picker where native camera capture is unavailable.

## Non-goals

- No live camera preview or video streaming.
- No new JavaScript camera library, backend endpoint, or storage format.
- No change to permissions, evidence limits, validation, or audit logging.

## Chosen approach

Use two native file inputs/forms with the same existing upload route. The upload action uses the normal file picker. The camera action uses `capture="environment"` and the same accepted image types, allowing supported mobile browsers to open the rear camera. Both submit through the existing `SalesAgendaEvidenceController` and `SalesAgendaEvidenceUploadService` flow.

## Verification

Verify rendered controls and labels, successful upload submission contract, existing validation behavior, and responsive layout on desktop and approximately 390px mobile.
