# Custom API Implementation Report

## Summary

The Jetstream + Livewire application was extended with a production-grade,
versioned REST API at `/api/v1` designed for Flutter, Vue.js and Python clients.
The API is framework-agnostic toward the frontend stack, uses a single JSON
envelope, short-lived access tokens with rotating refresh tokens, per-session
management, two-factor authentication, and an offline-first synchronization
engine. The existing unversioned `/api/*` endpoints were preserved unchanged for
backwards compatibility.

## Delivered features

### Versioning & envelopes
- URL-based versioning (`config('api.version')`, default `v1`) with route names
  prefixed `api.v1.`.
- Uniform success/error envelope built by `App\Support\ApiResponse`.
- Stable, machine-readable error codes (`App\Support\Enums\ApiErrorCode`).
- Centralized exception rendering for every framework exception, gated on
  `api/*` so web responses are untouched.

### Authentication & sessions
- `POST /auth/register`, `POST /auth/login`, `POST /auth/refresh`,
  `POST /auth/logout`, `POST /auth/logout-all`, `GET /auth/me`.
- Access tokens (Sanctum, 15 min default, `expires_at` enforced) + rotating
  refresh tokens stored as SHA-256 hashes.
- Refresh-token families with **reuse detection**: replaying a rotated token
  revokes the family and session and returns `TOKEN_REUSED`.
- `AuthSession` model so users can list and revoke individual devices.
- Two-factor challenge flow (`POST /auth/two-factor-challenge`) and management
  (`enable` / `confirm` / `disable` / `recovery-codes`).
- Password confirmation middleware (`423 PASSWORD_CONFIRMATION_REQUIRED`) that
  shares its cache key with the legacy middleware, so confirmation works across
  both API generations.
- Password reset via Laravel's broker; reset revokes all sessions.
- **Frontend-independent email verification**: `User` implements
  `MustVerifyEmail`; registration sends `VerifyEmailApi` linking to a signed API
  endpoint (`GET /auth/email/verify/{id}/{hash}`) that returns JSON (or redirects
  to `EMAIL_VERIFICATION_REDIRECT_URL`). Resend endpoint
  (`POST /auth/email/verification-notification`) is anti-enumeration. Enforcement
  is opt-in via `EMAIL_VERIFICATION_ENFORCE`.

### Profile & password
- View/update profile, change password (revokes all *other* sessions while
  keeping the caller authenticated), delete account with password verification.
- Duplicate-email validation on profile update.

### Offline-first synchronization
- Append-only `change_logs` journal; the auto-increment `id` is the
  server-authoritative revision.
- `GET /sync/cursor`, `GET /sync/pull`, `POST /sync/ack`, `POST /sync/push`.
- **Pull and ACK are separate**: pull advances `last_pulled_cursor`, an explicit
  ACK advances `acknowledged_cursor`. ACKing beyond what was delivered is
  rejected; re-ACKing is an idempotent no-op.
- Server-stored cursors keyed by session/token (`client_id`).
- **Resource-agnostic engine** (`App\Sync\SyncEngine`): resources are registered
  in `SyncResourceRegistry` via a `SyncResourceHandler`, so new entities need no
  engine, controller or validation changes (validation is registry-driven).
- **Per-resource conflict policies** (`manual_resolution`, `server_wins`,
  `client_wins`, `field_level_merge`); structural conflicts are never
  auto-resolved. `student` keeps `manual_resolution` for the `409 SYNC_CONFLICT`
  contract.
- Optimistic concurrency through a `version` column on `students`; stale writes
  return `409 SYNC_CONFLICT` with `server_data` to merge.
- **Idempotent push with payload binding**: an `operation_id` is reserved before
  the write, replaying the same payload returns the stored response
  (`replayed`), a different payload returns `409 IDEMPOTENCY_CONFLICT`, and an
  in-flight reservation returns `409 IDEMPOTENCY_IN_PROGRESS`.
- **ACK-aware pruning**: `sync:prune-change-log` (scheduled daily) deletes only
  entries older than the retention window and not beyond the slowest client's
  acknowledged cursor.
- Soft-delete tombstones so deletions propagate.

### Example resource
- `students` CRUD with ULID ids, client-selectable ids for offline creation,
  cursor pagination, `StudentPolicy` ownership checks and version-checked
  updates/deletes.

### Cross-cutting
- Named rate limiters for auth, password, 2FA, general and sync traffic, all
  configurable via `RATE_LIMIT_*` env vars (`max,minutes`).
- Form Requests for validation and API Resources for transformation.
- Feature tests for every endpoint.

## Key architectural decisions

| Decision | Rationale |
|----------|-----------|
| Access + refresh token pair instead of long-lived Sanctum tokens | Short blast radius; rotation + reuse detection protects long sessions |
| Refresh token stored hashed with `family_id` | Database leak does not expose usable tokens; enables family revocation |
| `change_logs.id` as the sync revision | Single monotonic source of truth; avoids clock skew |
| Per-record `version` separate from the global revision | Optimistic concurrency is resource-scoped; global cursor drives delivery |
| Session/token-derived `client_id` for sync cursors | No client-supplied identity to spoof; supports "resume where I left off" |
| Services hold all write logic (`StudentService`) | REST and sync push share one implementation, so versioning can't diverge |
| Envelope gated on `api/*` plus legacy routes untouched | New clients get a consistent contract without breaking existing ones |
| `foreignId` for `user_id`, ULIDs for syncable ids | Matches Laravel's bigint `users.id`; ULIDs give sortable, offline-generatable ids |

## Security model

- Passwords hashed with the `hashed` cast; password rules via `Password::default()`.
- Refresh tokens hashed at rest; one-time rotation with family revocation.
- Email/password login logging (`api.security.login_failed`) and refresh reuse
  logging (`api.security.refresh_reuse_detected`).
- Anti-enumeration on forgot-password (identical response whether or not the
  account exists).
- All 2FA management routes require a recent password confirmation.
- Rate limiting on every public and authenticated bucket.
- Authorization enforced with policies (`403 FORBIDDEN`), never by leaking 404s
  where a 403 is correct — and 404 for cross-user lookups that should not exist.

## Test coverage

```
php artisan test --compact
171 tests, 167 passed, 4 skipped, 556 assertions
```

- `tests/Feature/Api/V1` — auth, profile, password reset, password confirmation,
  2FA, sessions, students, sync, a generic-engine test proving an arbitrary
  registered resource round-trips and where each conflict policy is exercised,
  and email verification.
- `tests/Feature/Sync` — change-log pruning (retention, slowest-client
  acknowledgement, dry-run, disabled).
- 4 skipped tests are pre-existing Jetstream feature-gate skips, unchanged by
  this work.
- The legacy `/api/*` test suite stays green.

## Configuration reference

See `config/api.php`. Notable values:

- `tokens.access_token_ttl` (15 min), `tokens.refresh_token_ttl` (14 days)
- `tokens.refresh_token_reuse_policy` (`revoke_family`)
- `sync.pull_batch_size` (200), `sync.max_operations_per_push` (200)
- `sync.change_log_retention_days` (30; `0` disables pruning)
- `email_verification.enabled` (true), `enforce` (false), `expire_minutes` (60),
  `redirect_url` (null)
- `security.revoke_sessions_on_password_change` / `_reset` (both `true`)
- `pagination.default_per_page` (20) / `max_per_page` (100)

## Known limitations & follow-ups

- Email verification is implemented but **not enforced** by default so current
  clients keep working. Set `EMAIL_VERIFICATION_ENFORCE=true` to require it.
- Only `student` is registered in `SyncResourceRegistry`; adding another
  resource is a handler plus one `register()` line, with no engine changes.
- Cursor pagination on `students` orders by `id` (ULID) for stability; if you
  need another order, choose a unique, indexed sort key.

## File inventory

New code lives under `app/Exceptions`, `app/Http/Controllers/Api/V1`,
`app/Http/Middleware/EnsureApiEmailVerified.php`,
`app/Http/Middleware/EnsureApiPasswordConfirmed.php`, `app/Http/Requests/Api/V1`,
`app/Http/Resources/Api/V1`, `app/Notifications`,
`app/Models/{AuthSession,ChangeLog,IdempotencyKey,RefreshToken,Student,SyncCursor}.php`,
`app/Models/Concerns/UsesUlid.php`, `app/Policies`, `app/Rules`,
`app/Services/Api/V1`, `app/Sync`, `app/Support`, `config/api.php`, the
`2026_09_17_0523*`, `2026_09_17_0652*` migrations,
`database/factories/{AuthSession,RefreshToken,Student}Factory.php`,
`tests/Feature/Api/V1`, `tests/Feature/Sync` and `tests/Support`. Docs live in
`docs/api/`.

Modified: `bootstrap/{app,providers}.php`, `routes/{api,console}.php`,
`app/Providers/{AppServiceProvider,SyncServiceProvider}.php`,
`app/Models/User.php`, `app/Http/Controllers/Api/TwoFactorChallengeController.php`
(Sanctum 4 fix). See `CUSTOM_API_PORTABILITY.md` for the porting checklist.
