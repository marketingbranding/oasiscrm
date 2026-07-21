# Collaboration Rollback

Create and verify a database backup before any rollback. Do not run destructive production rollback commands without an approved restore point.

## Feature Controls

Disable advisory presence without disabling optimistic locking:

```env
PRESENCE_ENABLED=false
```

Disable notification polling while preserving notification records:

```env
NOTIFICATION_POLLING_ENABLED=false
```

After changing environment values:

```bash
php artisan optimize:clear
php artisan view:cache
```

Optimistic locking has no disable switch and should remain enabled during rollback.

## Stop Scheduled Cleanup

Temporarily remove or comment only these scheduler entries in `routes/console.php`, then deploy and verify `php artisan schedule:list`:

- `oasis:presence-cleanup`
- `oasis:notifications-cleanup`

Stopping cleanup does not make stale presence visible because live queries still apply the offline threshold. It also does not delete notifications.

## Migration Rollback

Latest additive migrations:

- `2026_07_21_000009_add_collaboration_operational_indexes`
- `2026_07_21_000008_create_system_task_runs_table`

Migration `000009` removes only operational indexes. Migration `000008` removes only task-run history. Neither removes users, memberships, activity logs, notifications, or presence rows.

Use `php artisan migrate:rollback --step=1` only after confirming the latest batch contains exactly the intended migration. Prefer restoring a backup when batch composition is uncertain.

Do not reverse notification or `updated_by` migrations merely to disable polling. Use configuration flags instead.

## Frontend Assets

1. Record the previous commit SHA before deployment.
2. Restore the application code to that approved SHA.
3. Run `npm ci && npm run build`, or restore the archived matching `public/build` directory.
4. Run `php artisan optimize:clear` and `php artisan view:cache`.
5. Confirm `public/build/manifest.json` references existing assets.

## Verification

1. Login with active and inactive accounts.
2. Confirm normal CRUD and optimistic conflicts still work.
3. Confirm disabled polling does not create browser errors that block CRM use.
4. Run `php artisan migrate:status` and `php artisan schedule:list`.
5. Open `/admin/system-health` when still available.
6. Review logs and verify no user, membership, notification, or activity-log data was deleted unexpectedly.
