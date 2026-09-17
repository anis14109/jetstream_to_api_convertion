# Offline-First Synchronization

The sync engine lets clients work offline and reconcile later without losing
data or silently overwriting newer server state. It is resource-agnostic: the
engine knows nothing about any specific entity. Each synchronizable resource is
described by a handler registered in `App\Sync\SyncResourceRegistry`
(see [Adding a resource](#adding-a-resource)).

## Concepts

- **Revision** — a monotonically increasing integer. Every write appends an
  entry to the `change_logs` journal and the entry's auto-increment `id` is the
  revision. Clients must never use their own clock; the cursor is server time.
- **`last_pulled_cursor`** — the highest revision delivered to this client.
  Advanced by **pull** only. Used to validate acknowledgements.
- **`acknowledged_cursor`** — the highest revision the client confirmed it has
  applied. Advanced by **ack** only. For backward compatibility the `cursor`
  field in responses is an alias of `acknowledged_cursor`.
- **Pull ≠ Ack.** Pulling changes does not mean the client persisted them. A
  client may crash between pull and apply, so only an explicit ACK moves the
  acknowledged cursor. Change-log pruning relies on the acknowledged cursor.
- **Version** — a per-record optimistic-concurrency counter on `students`. It is
  independent of the global revision.
- **Operation id** — a client-generated idempotency key (`operation_id`) for a
  push operation. Every operation id is stored with a fingerprint of its
  payload, so replays are safe and reuse with different data is rejected.

## Endpoints

All sync endpoints require authentication. `/sync/*` uses the `api-sync` rate
limit; the email-verification endpoints use their own limit.

### `GET /sync/cursor`

Returns this client's checkpoints.

```json
{
  "success": true,
  "message": "Sync cursor.",
  "data": {
    "cursor": 42,
    "acknowledged_cursor": 42,
    "last_pulled_cursor": 45,
    "server_time": "2026-09-17T12:00:00.000000Z"
  }
}
```

A new client starts at `0`. A client that lost local state should resume from
`acknowledged_cursor`, never `last_pulled_cursor`.

### `GET /sync/pull?cursor=0&limit=200`

Returns journal entries **after** `cursor`, in revision order. `limit` defaults
to `SYNC_PULL_BATCH_SIZE` (200) and is capped at 1000.

**Pull advances `last_pulled_cursor` only; it never acknowledges.** The cursor
used to resume should be persisted locally only after the changes are applied
(see [Recommended client loop](#recommended-client-loop)).

```json
{
  "success": true,
  "message": "Changes pulled.",
  "data": {
    "changes": [
      {
        "revision": 41,
        "entity_type": "student",
        "entity_id": "01JABC...",
        "operation": "updated",
        "version": 3,
        "data": { "id": "01JABC...", "name": "Alice v3", "version": 3 },
        "server_time": "2026-09-17T12:00:00.000000Z"
      }
    ],
    "next_cursor": 41,
    "has_more": false,
    "server_time": "2026-09-17T12:00:00.000000Z"
  }
}
```

`operation` is `created`, `updated` or `deleted`. Deleted entries carry a
tombstone `data` payload (`id`, `version`, `deleted_at`).

Keep calling while `has_more` is `true`, passing the returned `next_cursor`.

### `POST /sync/ack`

Confirms that the client has durably applied changes up to `cursor`.

```json
{ "cursor": 41 }
```

```json
{
  "success": true,
  "message": "Changes acknowledged.",
  "data": {
    "acknowledged_cursor": 41,
    "last_pulled_cursor": 41,
    "server_time": "2026-09-17T12:00:00.000000Z"
  }
}
```

Rules:

- `cursor` must be a non-negative integer (`422 VALIDATION_ERROR` otherwise).
- `cursor` must not exceed `last_pulled_cursor`; ACKing changes that were never
  delivered returns `422` on `cursor`.
- `cursor <= acknowledged_cursor` is an idempotent no-op that returns `200`, so
  clients can ACK safely after a retry.

### `POST /sync/push`

Applies a batch of client operations. Up to `SYNC_MAX_OPERATIONS_PER_PUSH`
(200) operations per request.

```json
{
  "operations": [
    {
      "operation_id": "01JOPCLIENT0000000000000001",
      "entity_type": "student",
      "entity_id": "01JABC...",
      "operation": "create",
      "data": { "name": "Bob" }
    },
    {
      "operation_id": "01JOPCLIENT0000000000000002",
      "entity_type": "student",
      "entity_id": "01JDEF...",
      "operation": "update",
      "version": 2,
      "data": { "name": "Alice v3" }
    },
    {
      "operation_id": "01JOPCLIENT0000000000000003",
      "entity_type": "student",
      "entity_id": "01JGHI...",
      "operation": "delete",
      "version": 1
    }
  ]
}
```

Rules:

- `entity_type` must be one of the registered resource types (`student` by
  default).
- `operation` is `create`, `update` or `delete`.
- `version` is required for `update` and `delete`.
- `data` is validated against the resource handler's rules
  (`data.name` is required for a `student` `create`/`update`).

#### Idempotency

Every `operation_id` is reserved before the operation runs, inside a
transaction. The same operation id may only be replayed with the **same**
payload:

| Situation | Result |
|-----------|--------|
| Same `operation_id`, same payload | Replayed from the stored response; nothing is applied again |
| Same `operation_id`, different payload | `409 IDEMPOTENCY_CONFLICT`; nothing is applied |
| Same `operation_id` still in flight | `409 IDEMPOTENCY_IN_PROGRESS`; retry shortly |

`IDEMPOTENCY_CONFLICT` and `IDEMPOTENCY_IN_PROGRESS` responses put the offending
operations in `data.idempotency_conflicts` / `data.pending`.

#### Success response (`200`)

```json
{
  "success": true,
  "message": "Sync push processed.",
  "data": {
    "applied": [
      { "operation_id": "...", "entity_type": "student", "entity_id": "01JABC...", "operation": "create", "version": 1, "revision": 42, "server_time": "..." }
    ],
    "conflicts": [],
    "idempotency_conflicts": [],
    "pending": [],
    "replayed": [ { "...previously stored response..." } ],
    "latest_revision": 42,
    "next_cursor": 42,
    "acknowledged_cursor": 0,
    "server_time": "..."
  }
}
```

An `applied` entry may include `resolution` when a conflict was resolved
automatically by the resource policy (`server_wins`, `client_wins`,
`field_level_merge`).

#### Manual conflicts (`409 SYNC_CONFLICT`)

If an operation cannot be resolved automatically, the request returns `409
SYNC_CONFLICT` with the conflict list in `data.conflicts`. Each conflict
includes `reason`, `client_version`, `server_version`, `server_data` and the
resource `policy`:

| `reason` | Meaning |
|----------|---------|
| `version_mismatch` | The record changed on the server since the client read it |
| `already_exists` | A `create` collided with an existing id |
| `missing` | The record does not exist on the server |
| `deleted` / `already_deleted` | The record was deleted before this operation |

Non-conflicting operations that were applied before the conflict in the same
batch are still committed, and all their idempotency records are completed, so
retrying the batch only replays what already succeeded.

## Conflict policies

Each resource declares a policy on its handler. Structural conflicts
(`already_exists`, `missing`) are **never** auto-resolved; only stale-version
conflicts (`version_mismatch`) are.

| Policy | Behaviour |
|--------|-----------|
| `manual_resolution` | Report the conflict. Used by `student` so clients keep the `409 SYNC_CONFLICT` contract. |
| `server_wins` | Discard the client write, keep the server record. |
| `client_wins` | Reapply the client write on top of the current server version. |
| `field_level_merge` | Merge the client's changed fields onto the current server record. |

## Adding a resource

1. Create a handler extending `App\Sync\Handlers\AbstractSyncResourceHandler`
   (implement `entityType()`, `conflictPolicy()`, `dataRules()`, `create()`,
   `update()`, `delete()` and `snapshot()`), or extend `StudentSyncHandler`.
2. Record every mutation through `App\Services\Api\V1\ChangeTracker::record()`
   **inside the same transaction** as the write.
3. Register it in `App\Providers\SyncServiceProvider`:
   `$registry->register(new AttendanceSyncHandler(...))`.

The engine, `PushRequest` validation, `SyncController` and `PullRequest` require
no changes: `entity_type` validation is driven by the registry.

## Pruning

`change_logs` grows with every write. The scheduled `sync:prune-change-log`
command (see `routes/console.php`) deletes only entries that:

- are older than `SYNC_CHANGE_LOG_RETENTION_DAYS` (default **30** days), **and**
- have a revision `<=` the **slowest** client's `acknowledged_cursor` for that
  user.

A user with no sync cursor is never pruned. Set
`SYNC_CHANGE_LOG_RETENTION_DAYS=0` to keep entries forever. Run it manually
with `php artisan sync:prune-change-log` (add `--dry-run` to preview,
`--days=N` to override the window).

## Recommended client loop

1. `GET /sync/cursor` on startup (or use the locally stored cursor).
2. `GET /sync/pull` repeatedly until `has_more` is false. Apply changes locally
   and persist `next_cursor` **after** they are committed.
3. `POST /sync/ack` with the persisted cursor once the batch is durably applied.
4. Queue local writes with a stable `operation_id`.
5. `POST /sync/push` the queue. On `200`, drop applied/replayed operations.
6. On `409 SYNC_CONFLICT`, resolve each conflict (usually `server_data` wins)
   and re-queue. On `IDEMPOTENCY_CONFLICT`, fix the payload; on
   `IDEMPOTENCY_IN_PROGRESS`, retry after a short delay.
7. Repeat.
