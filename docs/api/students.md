# Students (example resource)

`students` demonstrates the full resource contract: ULID ids, cursor
pagination, optimistic concurrency through a `version` column, and soft-delete
tombstones for synchronization.

Every student belongs to the authenticated user. Accessing another user's
student returns `403 FORBIDDEN` (route model binding hides nothing, the policy
decides).

## Resource shape

```json
{
  "id": "01JABC0000000000000000000A",
  "name": "Alice",
  "email": "alice@example.com",
  "notes": "First student",
  "version": 1,
  "created_at": "2026-09-17T12:00:00.000000Z",
  "updated_at": "2026-09-17T12:00:00.000000Z",
  "deleted_at": null
}
```

## Create

`POST /students` → `201`

| Field | Type | Rules |
|-------|------|-------|
| `name` | string | required, max 255 |
| `email` | string | optional, email, unique per user |
| `notes` | string | optional, max 2000 |
| `id` | string | optional, ULID/UUID selected by the client (offline creation) |

Passing `id` lets an offline client create the record locally with its own id
and replay it against the server without id changes. The server always starts
`version` at `1`.

## List

`GET /students?per_page=20&cursor=<opaque>`

Cursors are opaque; never construct or parse them. `per_page` defaults to
`API_DEFAULT_PER_PAGE` (20) and is capped by `API_MAX_PER_PAGE` (100).

```json
{
  "success": true,
  "message": "Students.",
  "data": {
    "items": [ { "id": "01J...", "name": "Alice", "version": 1 } ],
    "next_cursor": "eyJpZCI6IjAxSi4uLiJ9",
    "has_more": true,
    "per_page": 1
  }
}
```

## Show

`GET /students/{student}`

## Update

`PUT|PATCH /students/{student}`

| Field | Type | Rules |
|-------|------|-------|
| `name` | string | required, max 255 |
| `email` | string | optional, email, unique per user |
| `notes` | string | optional, max 2000 |
| `version` | integer | **required**, min 1 — the version the client last saw |

On success `version` increments by 1. If the server version no longer matches the
submitted `version`, the request is rejected with `409 SYNC_CONFLICT` and the
current server state in `data` (see [errors.md](errors.md)). The client should
merge and retry.

## Delete

`DELETE /students/{student}`

Body (optional): `{ "version": 3 }`. When provided, the delete is version
checked. The record is soft deleted (`deleted_at` is set, `version` increments)
so sync clients receive a tombstone.

## Errors

| Situation | Result |
|-----------|--------|
| Missing `name` | `422 VALIDATION_ERROR` |
| Missing `version` on update | `422 VALIDATION_ERROR` |
| Duplicate `email` for the user | `422 VALIDATION_ERROR` |
| Stale `version` | `409 SYNC_CONFLICT` |
| Another user's student | `403 FORBIDDEN` |
| Unknown id | `404 NOT_FOUND` |
