# Collaboration Manual Test

No browser automation stack is currently installed. Use two isolated browser profiles so each session has a separate authenticated user and session storage.

## Preconditions

- Run `php artisan migrate`.
- Run `npm run build` or `composer dev`.
- Confirm `php artisan schedule:list` includes hourly `oasis:presence-cleanup`.
- Give both users view/edit permission for the same branch.
- Use a Dana Talangan record that both users may edit.

## Two-user conflict scenario

1. Open the same Dana Talangan edit form as User A and User B.
2. Confirm each user sees the other user's display name with `sedang mengedit data ini`.
3. Change and save the record as User A.
4. Confirm User A's editing presence is cleared and User B receives a record update notification.
5. Submit the stale form as User B without reloading.
6. Confirm the form remains open and the conflict dialog appears instead of a generic network error.
7. Confirm the dialog shows User A's display name and modification time, without an email address.
8. Use `Salin Perubahan Saya` and paste into a text editor to verify the attempted values were copied.
9. Use `Muat Ulang Data`, accept the replacement warning, and confirm the newest record values load.

## Modal and status scenarios

1. Repeat the stale-save scenario in the Dana Talangan index edit modal and Database edit modal.
2. Confirm conflict and validation responses do not close the modal.
3. Drag a Work Planner card using a stale page and confirm the card returns to its old column while the shared conflict dialog opens.
4. Confirm no formula-column value is included in copied Database changes.

## Presence lifecycle

1. Close an edit modal and confirm the other session no longer reports editing after the next poll.
2. Hide an editing tab for more than 60 seconds and confirm it is no longer shown as editing.
3. Return to the tab and confirm editing presence resumes while the form is still open.
4. Trigger a validation error and confirm editing presence remains.
5. Close the tab and confirm presence disappears immediately or after the 60-second offline threshold.

## Notifications

1. Confirm the bell polls and displays only the current user's notifications.
2. Mark one notification as read and confirm the unread badge decreases.
3. Mark all as read and confirm the badge clears.
4. Trigger successful and failed manual syncs for Database, Konsumen Progress, and global Dana Talangan.
5. Confirm each result creates an initiator-only notification with branch or global scope and no spreadsheet row contents.

## Production scheduler

Install one cron entry:

```cron
* * * * * cd /path/to/oasiscrm && php artisan schedule:run >> /dev/null 2>&1
```

The repository registers cleanup, but deployment operators must verify the cron actually runs in the hosting environment.
