# 02 — Search/Filter by PIC

**Goal:** Add a text filter input to the calendar index so users can filter tasks by PIC name.

## Changes Required

### 1. Index Blade (`index.blade.php`)
- Add a text input `<input name="pic" ...>` inside the filter bar (after priority filter), styled like existing filter elements
- On change, auto-submit the form (like branch/project selects)
- Pre-fill from `request('pic')`

### 2. Controller (`ContentCalendarController.php`) — `index()`
- Accept `request('pic')` param
- If present, add a filter: `->whereJsonContains('pic_names', $pic)`
- Pass `$selectedPic` to the view

### 3. No migration needed
- `pic_names` is already a JSON column; `whereJsonContains` works out of the box

## Acceptance Criteria
- User types a PIC name → calendar re-renders showing only tasks where `pic_names` contains that name
- Empty input shows all tasks
- Works alongside existing branch/project/status/priority filters
- Input value persists across month navigation (hidden inputs or query params)
