# Offline-First Synchronization

The sync engine lets clients work offline and reconcile later without losing
data or silently overwriting newer server state.

## Concepts

- **Revision** — a monotonically increasing integer. Every write appends an
  entry to the `change_logs` journal and the entry's auto-increment `id` is the
  revision. Clients must never use their own clock; the cursor is server time.
- **Cursor** — the highest revision a client has consumed. The cursor is stored
  server-side per client (keyed by the auth session id, or the access token id
  for programmatic tokens) so a client that loses its local state can recover.
- **Version** — a per-record optimistic-concurrency counter on `students`. It is
  independent of the global revision.
- **Operation id** — a client-generated idempotency key for a push operation.
  Replaying the same `operation_id` never applies the operation twice.

## Endpoints

All three require authentication and use the `api-sync` rate limit.

### `GET /sync/cursor`

Returns the last revision this client pulled.

```json
{ "success": true, "message": "Sync cursor.", "data": { "cursor": 42, "server_time": "..." } }
```

A new client starts at `0`.

### `GET /sync/pull?cursor=0&limit=200`

Returns journal entries **after** `cursor`, in revision order. `limit` defaults
to `SYNC_PULL_BATCH_SIZE` (200) and is capped at 1000. Pulling advances the
stored cursor to `next_cursor`.

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

### `POST /sync/push`

Applies a batch of client operations atomically. Up to
`SYNC_MAX_OPERATIONS_PER_PUSH` (200) operations per request.

```json
{
  "operations": [
    {
      "operation_id": "01JOPCLIENT0000000000000001",
      "entity_type": "student",
      "entity_id": "01JABC...",
      "operation": "create",
      "data": { "name": "Bob", "email": "bob@example.com" }
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

- `entity_type` must be `student`.
- `operation` is `create`, `update` or `delete`.
- `version` is required for `update` and `delete`.
- `data.name` is required for `create` and `update`.

Success response (`200`):

```json
{
  "success": true,
  "message": "Sync push processed.",
  "data": {
    "applied": [
      { "operation_id": "...", "entity_type": "student", "entity_id": "01JABC...", "operation": "create", "version": 1, "revision": 42, "server_time": "..." }
    ],
    "conflicts": [],
    "replayed": [ { "...previously stored response..." } ],
    "latest_revision": 42,
    "next_cursor": 42,
    "server_time": "..."
  }
}
```

If **any** operation conflicts, the whole request returns `409 SYNC_CONFLICT`
with the conflict list in `data.conflicts`. Each conflict includes
`reason`, `client_version`, `server_version` and `server_data`:

| `reason` | Meaning |
|----------|---------|
| `version_mismatch` | The record changed on the server since the client read it |
| `already_exists` | A `create` collided with an existing id |
| `missing` | The record does not exist on the server |
| `deleted` / `already_deleted` | The record was deleted before this operation |

Non-conflicting operations that were applied before the conflict in the same
batch are still committed (the batch is processed inside a transaction but
conflicts are collected, not thrown, so partial progress is preserved and
reported).

## Recommended client loop

1. `GET /sync/cursor` on startup (or use the locally stored cursor).
2. `GET /sync/pull` repeatedly until `has_more` is false; apply changes locally
   and store `next_cursor`.
3. Queue local writes with a stable `operation_id`.
4. `POST /sync/push` the queue. On `200`, drop applied/replayed operations.
5. On `409`, merge each conflict (usually `server_data` wins) and re-queue.
6. Repeat.
