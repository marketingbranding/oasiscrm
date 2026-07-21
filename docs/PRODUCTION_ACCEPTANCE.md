# Oasis CRM Production Acceptance

This checklist is a release gate, not proof of acceptance. Record evidence and sign-off before declaring production acceptance.

## Release Record

- Date:
- Environment and URL:
- Commit SHA:
- Operator:
- Approver:
- Database backup reference:
- Current migration status reference:
- Current cron configuration reference:
- Result: PASS / FAIL / BLOCKED

## Pre-deployment Backup

1. Create and verify a database backup before running migrations.
2. Back up `.env` without placing it in source control or acceptance evidence.
3. Back up persistent `storage/app` content, including securely provisioned Google credentials where relevant.
4. Record the current commit SHA and `php artisan migrate:status` output.
5. Archive the current `public/build` assets or confirm they are reproducible from the recorded commit and lockfiles.
6. Record the installed cron entry and hosting timezone.
7. Confirm a tested restore path and responsible operator.

## Authentication

1. Open two isolated browser profiles and log in with two separate active accounts. Both sessions must remain independent.
2. Attempt login with an inactive account. Login must be rejected without revealing whether the email exists.
3. Log in, deactivate that account as superadmin, then make another page and JSON request. The next request must terminate access.
4. Confirm notifications remain stored but cannot be fetched while the account is inactive.

## Membership Authorization

For each check, test the normal UI and direct URL/request manipulation.

1. Primary branch with `can_view`: pages and authorized records are visible.
2. Secondary branch with `can_view`: branch selection and direct authorized URLs work.
3. Unassigned branch ID: page, record, export, import, and sync requests return 403/404.
4. `can_view=false`: branch data is inaccessible.
5. `can_edit=false`: create, update, delete, bulk mutation, and import are rejected.
6. `can_sync=false`: Database and Konsumen Progress sync are rejected.
7. `can_manage_members=false`: membership mutation is rejected where applicable.
8. Confirm global Dana Talangan sync is limited to superadmin or `pusat`.

## Presence

1. Open the same page as two users and confirm page presence appears.
2. Open different pages and confirm users are not shown in the wrong context.
3. Open the same editable record and confirm editing presence appears.
4. Open multiple tabs for one account and confirm its display name is deduplicated.
5. Hide an editing tab for more than 60 seconds and confirm it becomes idle or disappears as an editor.
6. Return to the tab and confirm editing resumes if the form is still open.
7. Close a tab and confirm immediate cleanup or expiry after the offline threshold.
8. Disable a present user and confirm the name is excluded immediately from presence responses.
9. Temporarily block the presence endpoint and confirm core CRUD remains usable.

## Conflict Handling

Repeat with two users for each module.

1. Dana Talangan standard edit form: User A saves, User B receives conflict and cannot overwrite.
2. Dana Talangan modal: conflict keeps the modal and values open.
3. Work Planner standard form: latest values remain protected.
4. Work Planner drag/drop: stale card returns to its prior column and opens the shared conflict dialog.
5. Database record modal: stale or sync-replaced records return the structured conflict response.
6. Use `Salin Perubahan Saya`; verify success or the manual-copy fallback message.
7. Select `Muat Ulang Data`, accept the replacement warning, and verify current server data loads.

## Notifications

1. Confirm latest notifications and unread badge are private per user.
2. Mark one notification read and verify only that row changes.
3. Mark all read and verify unread count becomes zero.
4. Change a membership and verify the affected user receives a notification.
5. Update a record while another authorized editor is present and verify an update notification.
6. Trigger a stale save and verify a deduplicated conflict notification.
7. Trigger manual Database and Konsumen Progress sync success/failure notifications.
8. Trigger global Dana Talangan sync and verify it is labeled global.
9. Disable notification polling and confirm all CRM CRUD remains usable.

## Scheduler and Retention

1. Run `php artisan schedule:list` and verify ten-minute syncs, hourly presence cleanup, and weekly notification cleanup.
2. Install the once-per-minute production cron documented below.
3. Run `php artisan oasis:presence-cleanup` and confirm a successful `system_task_runs` row and deleted-row summary.
4. Run `php artisan oasis:notifications-cleanup --dry-run`; no rows may be deleted.
5. Confirm unread notifications are never selected for retention cleanup.
6. Temporarily pause cron. Presence older than the offline threshold must remain hidden even before physical cleanup.
7. Restore cron and confirm `/admin/system-health` reports a fresh cleanup run.
8. On a non-production environment, optionally run `php artisan oasis:presence-diagnostics --benchmark=100000`; synthetic rows must be rolled back automatically.

```cron
* * * * * cd /path/to/oasiscrm && php artisan schedule:run >> /dev/null 2>&1
```

## System Health

1. Superadmin can open `/admin/system-health`; normal users receive 403.
2. Database, migrations, schedule registration, cleanup freshness, presence metrics, notification metrics, storage, and Vite status are shown.
3. Confirm the page contains no passwords, API keys, database credentials, spreadsheet IDs, user emails, session values, or exception traces.

## Post-deployment Smoke Test

1. Login and dashboard.
2. Primary and secondary branch access.
3. One authorized CRUD update.
4. One two-user conflict scenario.
5. One presence scenario.
6. One private notification scenario.
7. One manual sync scenario.
8. `php artisan schedule:list` and system-health review.
9. Review application logs for new failures.

Do not mark production acceptance complete until the two-user execution record in `COLLABORATION_TESTING.md` is filled by an operator.
