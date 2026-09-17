# Custom REST API — v1

A versioned, envelope-based REST API for Flutter, Vue.js and Python clients.
It runs alongside the legacy unversioned `/api/*` endpoints, which remain
unchanged for the existing first-party clients.

- **Base URL:** `/api/v1`
- **Content type:** `application/json` (always send `Accept: application/json`)
- **Auth:** `Authorization: Bearer <access_token>`

## Documents

| Document | Contents |
|----------|----------|
| [authentication.md](authentication.md) | Register, login, tokens, refresh, 2FA, password reset/confirm, sessions |
| [students.md](students.md) | Example resource: cursor pagination, optimistic concurrency, CRUD |
| [sync.md](sync.md) | Offline-first pull/push contract, cursors, idempotency, conflicts |
| [errors.md](errors.md) | Error envelope, stable error codes, status codes, rate limits |

## Response envelope

Every versioned endpoint returns the same JSON envelope.

Success (`2xx`):

```json
{
  "success": true,
  "message": "Login successful.",
  "data": { }
}
```

Error (`4xx` / `5xx`):

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

`data` is always present on success (it is `null` for operations that return
nothing). On errors, `errors` is present for validation failures and `data` is
present for conflicts (`SYNC_CONFLICT`).

> The legacy unversioned endpoints keep their original shapes (for example
> login returns a top-level `user` + `token`) and do **not** use this envelope.

## Authentication at a glance

1. `POST /api/v1/auth/register` or `POST /api/v1/auth/login` returns an
   **access token** (short lived) and a **refresh token** (long lived, rotated).
2. Send the access token as `Authorization: Bearer <token>` on protected routes.
3. When the access token expires, call `POST /api/v1/auth/refresh` with the
   refresh token to get a new pair. Refresh tokens rotate on every use.
4. Presenting a refresh token that has already been rotated revokes the whole
   token family and the session (`TOKEN_REUSED`).

See [authentication.md](authentication.md) for the full lifecycle.

## Versioning policy

- The version lives in the URL path (`/api/v1`). `config('api.version')`
  controls the documented version.
- Breaking changes ship as `/api/v2`; `v1` stays supported in parallel.
- Additive changes (new optional fields, new endpoints) do not bump the version.

## Conventions

- **Timestamps** are ISO-8601 UTC strings (`2026-09-17T12:00:00.000000Z`).
- **Identifiers** of synchronizable resources are ULIDs (26 chars, sortable).
- **Users** keep their integer primary key (`id`).
- **Pagination** uses opaque cursors, never offsets (`cursor`, `next_cursor`).
- **Errors** should be handled by branching on `code`, never on `message`.
