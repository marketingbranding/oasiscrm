# 08 — Bulk Actions

**Goal:** Add checkbox selection to calendar tasks and a bulk action bar to batch-change status, priority, or PIC, and bulk-delete.

## Changes Required

### 1. Index Blade (`index.blade.php`)
- Add a checkbox to each task card (small, top-left corner)
- Add a floating/sticky bulk action bar at bottom of calendar:
  - Shows count of selected: "2 task dipilih"
  - Dropdown for **Status** (todo, in_progress, completed, lost_track)
  - Dropdown for **Priority** (low, medium, high, urgent)
  - **PIC** tag input (reuse `picTags` Alpine component) to append PICs
  - **Hapus** button (red, confirm dialog)
  - **Batal** button to clear selection
- Use Alpine.js `x-data` for selection state:
  - `selectedIds: []`
  - `toggle(id)`, `selectAll()`, `clearSelection()`

### 2. Controller — add `bulkUpdate()` and `bulkDelete()` methods
- `POST /content-calendar/bulk-update`
  - Accepts `ids` (array), `status`, `priority`, `pic_names`
  - Loops through items, updates only non-null fields
  - Appends (doesn't replace) `pic_names` if provided
- `POST /content-calendar/bulk-delete`
  - Accepts `ids` (array)
  - Deletes all matching items within user's branch scope

### 3. Routes
- `Route::post('/content-calendar/bulk-update', [ContentCalendarController::class, 'bulkUpdate'])->name('content-calendar.bulk-update')`
- `Route::post('/content-calendar/bulk-delete', [ContentCalendarController::class, 'bulkDelete'])->name('content-calendar.bulk-delete')`

### 4. Request validation (optional)
- Add `BulkUpdateRequest` or validate inline in controller
- `ids` required|array, `ids.*` exists:content_items,id
- `status` nullable|in:todo,in_progress,completed,lost_track
- `priority` nullable|in:low,medium,high,urgent
- `pic_names` nullable|array

### 5. JavaScript UX details
- Checkbox area: clicking the card text still opens detail modal, clicking checkbox toggles selection (no event conflict via `@click.stop`)
- "Select All" checkbox in the header (selects all visible tasks on current month)
- Selected cards get a highlight ring (e.g., `ring-2 ring-blue-500`)
- Bulk action bar is `position: sticky; bottom: 0` with a white background and top border
- On submit, confirm dialog for delete, no confirm for status/priority/PIC update

## Acceptance Criteria
- Checkboxes appear on each task card without breaking the click-to-detail behavior
- Selecting tasks shows the bulk action bar
- Status/Priority batch update works on all selected tasks
- PIC append adds to existing PIC list without replacing
- Bulk delete with confirmation works
- Select All / Deselect All toggle works
- Bulk bar stays visible while scrolling (sticky)
