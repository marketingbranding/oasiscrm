# 04 — Task Detail Modal

**Goal:** Click a task card in the calendar view to open a modal with full task details (title, detail, PIC, dates, priority, status, notes), avoiding navigation to the edit page.

## Changes Required

### 1. Add a new route + controller method (or reuse show)
- `GET /content-calendar/{id}/detail` returning JSON
- Or render a modal partial via `GET /content-calendar/{id}/detail?format=modal` returning Blade HTML
- JSON approach is cleaner: Controller returns `$content->load('creator')` with all fields

### 2. Index Blade (`index.blade.php`)
- Change task card `<a href="{{ route('content-calendar.edit', ...) }}">` to `@click.prevent="openDetail({{ $item->id }})"`
- Add an Alpine.js modal component:
  - `x-data="taskDetailModal()"`
  - Modal overlay with close on backdrop click / Escape
  - Full detail layout: title, project, PIC chips, priority badge, status badge, date range, duration, task detail text, notes, creator
  - Edit button inside modal linking to edit page

### 3. Alpine component (`taskDetailModal`)
- `openDetail(id)` → fetch `/content-calendar/{id}/detail` → populate state
- State: `open: false, loading: false, task: null`
- Loading skeleton while fetching
- Close handler resets state

### 4. Controller — add `detail($id)` method
- `ContentItem::with('creator')->findOrFail($id)`
- Return JSON with all fields (cast dates, pic_names)

### 5. Route
- `Route::get('/content-calendar/{content_calendar}/detail', [ContentCalendarController::class, 'detail'])->name('content-calendar.detail')`

## Acceptance Criteria
- Clicking a task card opens a modal (not navigating away)
- Modal shows all task fields neatly laid out
- Close via × button, backdrop click, or Escape
- Edit button in modal goes to edit page
- Modal content is loaded via AJAX (fast, doesn't block page render)
- Works for tasks in any status/overdue state
