# Errors & Rate Limits

## Error codes

Clients must branch on `code`. Messages are human readable and may change.

| `code` | HTTP | Meaning |
|--------|------|---------|
| `UNAUTHENTICATED` | 401 | Missing, invalid, expired or revoked access token |
| `INVALID_CREDENTIALS` | 401 | Wrong email/password on login |
| `TWO_FACTOR_REQUIRED` | 200 | Login succeeded but a 2FA challenge is required (see `two_factor_required`) |
| `INVALID_TOKEN` | 401 | Refresh token unknown, malformed or revoked |
| `TOKEN_EXPIRED` | 401 | Refresh token past its expiry |
| `TOKEN_REUSED` | 401 | A rotated refresh token was replayed; the token family was revoked |
| `PASSWORD_CONFIRMATION_REQUIRED` | 423 | The action needs a fresh password confirmation |
| `EMAIL_NOT_VERIFIED` | 403 | The account's email is not verified and the route requires it (only when `EMAIL_VERIFICATION_ENFORCE=true`) |
| `FORBIDDEN` | 403 | Authenticated but not allowed to perform the action |
| `NOT_FOUND` | 404 | Resource does not exist or is not visible to this user |
| `VALIDATION_ERROR` | 422 | Request failed validation (see `errors`) |
| `SYNC_CONFLICT` | 409 | Stale version or sync operation conflict (see `data.conflicts`) |
| `IDEMPOTENCY_CONFLICT` | 409 | A sync `operation_id` was reused with a different payload (see `data.idempotency_conflicts`) |
| `IDEMPOTENCY_IN_PROGRESS` | 409 | A sync `operation_id` with the same payload is still being processed (see `data.pending`) |
| `TOO_MANY_REQUESTS` | 429 | Rate limit exceeded; check the `Retry-After` header |
| `OPERATION_FAILED` | 4xx | Other client error surfaced by the framework |
| `INTERNAL_ERROR` | 500 | Unexpected server error |

### Validation errors (`422`)

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "email": ["The email has already been taken."],
    "password": ["The password field confirmation does not match."]
  }
}
```

### Conflicts (`409`)

```json
{
  "success": false,
  "message": "Resource has changed on the server.",
  "code": "SYNC_CONFLICT",
  "data": {
    "entity_type": "student",
    "entity_id": "01J...",
    "client_version": 1,
    "server_version": 2,
    "server_data": { "name": "Alice v2", "version": 2 },
    "reason": "version_mismatch"
  }
}
```

## Rate limits

Limits are named buckets configured through `config/api.php`
(`RATE_LIMIT_*` environment variables). Each value is `max,minutes`.

| Limiter | Default | Applies to |
|---------|---------|------------|
| `api-register` | 5 / 15 min | `POST /auth/register` |
| `api-login` | 10 / 1 min | `POST /auth/login` (per email + IP) |
| `api-refresh` | 60 / 1 min | `POST /auth/refresh` |
| `api-password-reset` | 5 / 15 min | `forgot-password`, `reset-password` |
| `api-password-confirmation` | 10 / 1 min | `POST /user/confirm-password` |
| `api-two-factor` | 10 / 1 min | `POST /auth/two-factor-challenge` |
| `api` | 240 / 1 min | All other authenticated routes |
| `api-sync` | 120 / 1 min | `/sync/*` routes |
| `api-email-verification` | 3 / 1 min | Email verification link and resend |

When a limit is exceeded the API returns `429` with `code:
TOO_MANY_REQUESTS` and the standard `Retry-After` / `X-RateLimit-*` headers.
