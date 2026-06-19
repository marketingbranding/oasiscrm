# Fix: Proyek filter breaks when branch selected + Add to Dashboard & Calendar

## Bug: Type mismatch in JS comparison

`@json($p->branch_id)` outputs the integer as a JS number (`1`), but `select.value` returns a string (`"1"`). Strict `===` fails: `1 === "1"` is always `false`.

**Fix:** Change `===` to `==` on 4 lines across 4 files:

| File | Line |
|---|---|
| `resources/views/crm/content-calendar/create.blade.php` | 242 |
| `resources/views/crm/content-calendar/edit.blade.php` | 258 |
| `resources/views/crm/lead-events/create.blade.php` | 393 |
| `resources/views/crm/lead-events/edit.blade.php` | 393 |

Change: `projectData[i].branch === branchId` → `projectData[i].branch == branchId`

---

## Feature: Add Proyek filter to Dashboard

### `DashboardController.php`
1. Import `LeadMaster`
2. In `index()`:
   - For superadmin: `$projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get()`
   - For non-superadmin: `$projects = LeadMaster::where('branch_id', $user->branch_id)->where('is_active', true)->orderBy('project_name')->get()`
3. Read `$selectedProject = $request->get('project_name')`
4. Add `->when($selectedProject, fn($q) => $q->where('project_name', $selectedProject))` to all 4 ContentItem queries
5. Pass `$projects` and `$selectedProject` to view

### `crm/dashboard.blade.php`
1. After Cabang `<select>`, add Proyek `<select name="project_name" onchange="this.form.submit()">` with options from `$projects`
2. Both dropdowns inside the `@if(isset($branches) ...)` block
3. Superadmin sees both Cabang + Proyek; admin cabang sees only Proyek

---

## Feature: Add Proyek filter to Content Calendar index

### `ContentCalendarController.php` (index method)
1. Import `LeadMaster`
2. Read `$selectedProject = $request->get('project_name')`
3. Query projects same pattern as DashboardController
4. Add `->when($selectedProject, fn($q) => $q->where('project_name', $selectedProject))` to ContentItem query
5. Pass `$projects` and `$selectedProject` to view

### `content-calendar/index.blade.php`
1. After Cabang `<select>`, add Proyek `<select name="project_name" onchange="this.form.submit()">`
2. Preserve `project_name` in prev/next month links and delete forms
3. Both dropdowns inside the same `@if` block

---

## Files to touch (8 total)
1. `resources/views/crm/content-calendar/create.blade.php` — `===` → `==`
2. `resources/views/crm/content-calendar/edit.blade.php` — `===` → `==`
3. `resources/views/crm/content-calendar/index.blade.php` — add project filter
4. `resources/views/crm/lead-events/create.blade.php` — `===` → `==`
5. `resources/views/crm/lead-events/edit.blade.php` — `===` → `==`
6. `resources/views/crm/dashboard.blade.php` — add project filter
7. `app/Http/Controllers/Crm/DashboardController.php` — pass + filter projects
8. `app/Http/Controllers/Crm/ContentCalendarController.php` — pass + filter projects
