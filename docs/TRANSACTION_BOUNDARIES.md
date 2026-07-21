# Collaboration Transaction Boundaries

## Local Atomic Operations

- Membership user fields, pivots, and membership audit records use a local database transaction.
- Optimistic-lock protected updates re-read and lock the local row before accepting a mutation.
- Database and Konsumen Progress cache replacement use local database transactions.
- Notification creation is best effort and does not roll back a successful business update.
- Presence cleanup and notification cleanup are independent operational tasks.

## Google Sheets Boundaries

A local database transaction cannot atomically commit with Google Sheets.

Database record updates reserve the local optimistic version in a short transaction, then perform one Google batch values request. Local business values change only after Google acknowledges the batch. On failure, old local values are retained and the row is marked failed for operator review.

Database deletes write Google tombstone metadata before marking the local row deleted. A failed remote tombstone leaves the local row visible.

Dana Talangan web updates save locally before pushing to Google because the local table is the dashboard cache. A push failure is reported as partial state and retained in `sync_status`/`last_sync_error`; it is not described as total success.

Full sync operations are reconciliation processes, not distributed transactions. They must report warning/failure counts truthfully. Automatic blind write retries are not used because append and row mutation operations require idempotent identifiers first.

## Recovery

1. Inspect module sync status and `/admin/system-health`.
2. Correct credentials/network or sheet metadata issues.
3. Retry through the explicit manual sync action.
4. Verify local and Google status before closing the incident.
5. Never edit `sync_status` directly to manufacture a successful state.
