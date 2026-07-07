# 03 — Dashboard Widgets

**Goal:** Add overdue count, upcoming deadlines, and per-PIC task count widgets to the main CRM dashboard.

## Changes Required

### 1. Determine dashboard location
- Check which Blade file renders the main dashboard (`dashboard.blade.php` or similar)
- Add a new section/row of stat cards

### 2. Controller (`DashboardController` or home)
- **Overdue count:** `ContentItem::where('status', '!=', 'completed')->whereDate('deadline_date', '<', now())->count()`
- **Upcoming deadlines (next 7 days):** `ContentItem::where('status', '!=', 'completed')->whereDate('deadline_date', '>=', now())->whereDate('deadline_date', '<=', now()->addDays(7))->count()`
- **Per-PIC task counts (top 5):** query all non-completed tasks, extract `pic_names`, flatten, group by name, sort desc, take 5
- Respect branch scope (user's branch unless `canViewAllBranches`)

### 3. Dashboard Blade
- Style stat cards in the same bold border-2 black/white aesthetic as the rest of the CRM
- Overdue: red accent, Upcoming: yellow/amber, Total PICs: green
- PIC list as a compact table or chip list with counts

### 4. No migration needed
- All data already exists in `content_items`

## Acceptance Criteria
- Overdue count reflects tasks past deadline, not completed
- Upcoming count shows tasks due within 7 days
- PIC list shows all unique PICs with task counts, sorted by most tasks
- Widgets respect branch visibility
- Styling consistent with existing dashboard
