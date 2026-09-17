# Authentication

All routes are prefixed with `/api/v1`.

## Token model

| Token | Lifetime (config) | Storage | Notes |
|-------|-------------------|---------|-------|
| Access token | `ACCESS_TOKEN_TTL` (15 min) | Sent as `Bearer` | Sanctum token bound to a session |
| Refresh token | `REFRESH_TOKEN_TTL` (20160 min = 14 days) | Sent in request body | Rotated on every use, hashed at rest |
| 2FA challenge token | `TWO_FACTOR_CHALLENGE_TTL` (5 min) | Returned by login | Only completes the 2FA challenge |

Refresh tokens rotate. Each refresh returns a **new** refresh token and
immediately invalidates the previous one. The tokens form a *family*; replaying
an already-rotated refresh token revokes the entire family and the session so a
stolen token cannot be used silently.

### Token payload

Returned by `register`, `login` (without 2FA) and `refresh`:

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user": {
      "id": 1,
      "name": "Ada Lovelace",
      "email": "ada@example.com",
      "email_verified_at": null,
      "two_factor_enabled": false,
      "profile_photo_url": "https://...",
      "created_at": "2026-09-17T12:00:00.000000Z",
      "updated_at": "2026-09-17T12:00:00.000000Z"
    },
    "access_token": "12|xxxxxxxx",
    "token_type": "Bearer",
    "access_expires_at": "2026-09-17T12:15:00.000000Z",
    "refresh_token": "yyyyyyyyyyyyyyyy",
    "refresh_expires_at": "2026-10-01T12:00:00.000000Z",
    "session": {
      "id": "01JABC...",
      "name": "Pixel 9",
      "ip_address": "203.0.113.10",
      "user_agent": "Dart/3.5",
      "current": true,
      "last_used_at": "2026-09-17T12:00:00.000000Z",
      "revoked_at": null,
      "created_at": "2026-09-17T12:00:00.000000Z"
    }
  }
}
```

---

## Register

`POST /auth/register` — rate limit `api-register`

| Field | Type | Rules |
|-------|------|-------|
| `name` | string | required, max 255 |
| `email` | string | required, email, unique |
| `password` | string | required, confirmed, `Password::default()` |
| `password_confirmation` | string | required when `password` is present |
| `device_name` | string | optional, max 255 (used as the session name) |

Returns `201` with the [token payload](#token-payload).

## Login

`POST /auth/login` — rate limit `api-login`

| Field | Type | Rules |
|-------|------|-------|
| `email` | string | required, email |
| `password` | string | required |
| `device_name` | string | optional, max 255 |

Without 2FA, returns `200` with the [token payload](#token-payload).

With 2FA enabled, returns `200` and **no tokens**:

```json
{
  "success": true,
  "message": "Two-factor authentication is required.",
  "data": {
    "two_factor_required": true,
    "two_factor_token": "9|zzzzzzzz",
    "user": { "id": 1, "name": "Ada Lovelace" }
  }
}
```

Complete it with [Two-factor challenge](#two-factor-challenge).

Invalid credentials return `401 INVALID_CREDENTIALS`.

## Refresh

`POST /auth/refresh` — rate limit `api-refresh`

| Field | Type | Rules |
|-------|------|-------|
| `refresh_token` | string | required |

Returns `200` with a fresh [token payload](#token-payload).

Errors:

- `401 INVALID_TOKEN` — unknown/malformed/revoked token.
- `401 TOKEN_EXPIRED` — past expiry.
- `401 TOKEN_REUSED` — the token was already rotated. The whole family and
  session are revoked; send the user back to login.

## Current user

`GET /auth/me` → `{ "data": { user } }`

## Logout

- `POST /auth/logout` — revokes the **current** session (access + refresh tokens).
- `POST /auth/logout-all` — revokes **every** session for the user.

Both return `200` with `data: null`.

---

## Password confirmation

`POST /user/confirm-password`

| Field | Type |
|-------|------|
| `password` | required, string |

Confirms a recent password so privileged actions (2FA enable/disable/recovery
codes) are allowed. On success the confirmation is cached for a short window
and shared with the legacy middleware. A wrong password returns
`422 VALIDATION_ERROR` on the `password` key.

Protected 2FA routes return `423 PASSWORD_CONFIRMATION_REQUIRED` until
confirmation succeeds.

## Password reset

`POST /auth/forgot-password` — rate limit `api-password-reset`

| Field | Type |
|-------|------|
| `email` | required, email |

Always returns the same success response so the API never reveals whether an
account exists. If the account exists, a reset link is sent through the
standard Laravel `ResetPassword` notification.

`POST /auth/reset-password` — rate limit `api-password-reset`

| Field | Type | Rules |
|-------|------|-------|
| `token` | string | required |
| `email` | string | required, email |
| `password` | string | required, confirmed, `Password::default()` |
| `password_confirmation` | string | required |

On success every existing session is revoked
(`REVOKE_SESSIONS_ON_PASSWORD_RESET`). An invalid token returns
`422 VALIDATION_ERROR`.

## Two-factor authentication

TOTP-based (Google Authenticator compatible).

Enable the flow (all require a recent password confirmation):

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/two-factor/enable` | Start setup |
| `POST` | `/two-factor/confirm` | Confirm the 6-digit code |
| `GET` | `/two-factor/recovery-codes` | Regenerate 8 recovery codes |
| `DELETE` | `/two-factor` | Disable 2FA |

`POST /two-factor/enable` returns:

```json
{
  "success": true,
  "message": "Two-factor authentication setup initialized.",
  "data": {
    "secret": "JBSWY3DPEHPK3PXP",
    "qr_code": "data:image/svg+xml;base64,...",
    "recovery_codes": ["abc12-def34", "..."]
  }
}
```

`POST /two-factor/confirm` body `{ "code": "123456" }`. An invalid code returns
`422 VALIDATION_ERROR` on `code`.

`GET /two-factor/recovery-codes` returns a new set of 8 one-time codes.

### Two-factor challenge

`POST /auth/two-factor-challenge` — rate limit `api-two-factor`

| Field | Type | Rules |
|-------|------|-------|
| `two_factor_token` | string | required (from login) |
| `code` | string | required without `recovery_code`, 6 digits |
| `recovery_code` | string | required without `code` |
| `device_name` | string | optional, max 255 |

Returns `200` with the [token payload](#token-payload). An invalid code returns
`422 VALIDATION_ERROR`.

## Sessions

`GET /sessions` — lists the user's active sessions. The session used by the
current access token has `current: true`.

`DELETE /sessions/{session}` — revokes a session (and its refresh tokens).
Revoking a session that belongs to another user returns `404 NOT_FOUND`.
