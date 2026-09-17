# Custom API Portability Guide

This document describes how to lift the custom versioned API out of this
Jetstream application and into a fresh Laravel application. The v1 API has **no
runtime dependency on Jetstream, Fortify or Livewire** — it only needs Sanctum,
the `User` model, Laravel's password broker and the `ResetPassword`
notification.

## 1. Requirements

- PHP 8.3+
- Laravel 13+
- `laravel/sanctum` ^4
- `pragmarx/google2fa` (comes with Jetstream's Fortify stack; add it explicitly
  if you drop Fortify)

```bash
composer require laravel/sanctum
composer require pragmarx/google2fa
php artisan install:api   # publishes Sanctum config + personal_access_tokens
```

## 2. Copy the API code

Everything below is self-contained. Copy the directories/files verbatim.

```
app/
  Console/Commands/PruneChangeLog.php
  Exceptions/
    ApiException.php
    ConflictException.php
  Http/
    Controllers/Api/V1/            # 10 controllers
    Middleware/EnsureApiEmailVerified.php
    Middleware/EnsureApiPasswordConfirmed.php
    Requests/Api/V1/               # form requests
    Resources/Api/V1/              # API resources
  Models/
    AuthSession.php
    ChangeLog.php
    IdempotencyKey.php
    RefreshToken.php
    Student.php
    SyncCursor.php
    Concerns/UsesUlid.php
  Notifications/VerifyEmailApi.php
  Policies/StudentPolicy.php
  Providers/SyncServiceProvider.php
  Rules/UuidOrUlid.php
  Services/Api/V1/                 # AuthService, RefreshTokenService, ...
  Sync/                            # SyncEngine, registry, contract, handlers
  Support/
    ApiResponse.php
    Enums/ApiErrorCode.php
config/api.php
database/
  factories/AuthSessionFactory.php
  factories/RefreshTokenFactory.php
  factories/StudentFactory.php
  migrations/2026_09_17_0523*.php  # 7 migrations
  migrations/2026_09_17_0652*.php  # idempotency completed_at + cursor split
tests/Feature/Api/V1/              # feature tests + ApiV1Helpers trait
tests/Feature/Sync/                # pruning tests
tests/Support/                     # in-memory sync handler used by the engine test
```

`Student` is an **example** synchronizable resource. Keep it to prove the
contract, then replace or extend it with your own resources by writing a
`SyncResourceHandler` and registering it in `SyncServiceProvider` (see
`docs/api/sync.md`).

### Concurrency primitives used (all portable)

No database-specific SQL is used, so the same code runs on MySQL 8, PostgreSQL
13+ and SQLite:

- **Idempotency** relies on a unique index on `idempotency_keys (user_id,
  key_hash)` plus a reservation row inserted in its own savepoint
  (`DB::transaction`). On PostgreSQL, which aborts a transaction after a
  constraint violation, the savepoint keeps the surrounding push transaction
  usable. After a violation the existing row is read with `lockForUpdate()` so
  the winner's just-committed state is visible.
- **Cursor monotonicity** is a single `UPDATE` whose value is
  `CASE WHEN column > :value THEN column ELSE :value END`, so the database (not
  application logic) decides whether a checkpoint moves.
- **ACK** locks the cursor row with `lockForUpdate()` inside a transaction, with
  the atomic `max` update as a second line of defence.

Most suites use the in-memory SQLite database and assert the mechanisms that
make concurrency safe (unique-index violation handling, reservation state
machine, monotonic updates). `tests/Feature/Sync/ConcurrencyTest.php` goes
further: it runs the operations in genuinely parallel OS processes against a
shared file-backed SQLite database, releasing every worker through a filesystem
barrier so they collide on the same rows, then asserts that a shared operation
id is reserved exactly once and that concurrently-acknowledged cursors converge
on the maximum value without ever regressing. SQLite cannot upgrade a deferred
read transaction to a writer without risking `SQLITE_BUSY`, so the worker
retries lock contention; production runs on MySQL/PostgreSQL, where
`lockForUpdate()` serialises the writers. The test skips automatically when
`pdo_sqlite` or child-process spawning is unavailable.

## 3. Wire it up

### `routes/api.php`

Merge the `v1` block from this project's `routes/api.php`. Route names are
prefixed `api.v1.`. Keep the public routes outside `auth:sanctum` and everything
else inside it.

### `bootstrap/app.php`

Register the middleware alias and the exception renderers:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'api-confirm-password' => EnsureApiPasswordConfirmed::class,
        'api-verified' => EnsureApiEmailVerified::class,
    ]);
})
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
    );

    // Copy every render() closure that is gated on $request->is('api/*').
})
```

The renderers are gated on `$request->is('api/*')` so they never alter the
response shape of the web/Jetstream frontend.

### `app/Providers/AppServiceProvider.php`

Copy `configureRateLimiting()` and its helpers (`limit`, `credentialKey`,
`userKey`) and call it from `boot()`. The named limiters are referenced by the
routes via `throttle:api-login` etc.

### `app/Providers/SyncServiceProvider.php`

Register it in `bootstrap/providers.php`. It binds `SyncResourceRegistry` as a
singleton and registers the handlers a client can synchronize. Add your own
resources here.

### `routes/console.php`

Schedule the pruning command:

```php
Schedule::command('sync:prune-change-log')->daily()->withoutOverlapping();
```

### `app/Models/User.php`

Add the relations and traits the API relies on (see the diff of this project's
`User`): `authSessions()`, `refreshTokens()`, `students()`, the Sanctum
`HasApiTokens` trait, `implements MustVerifyEmail`, and the
`sendEmailVerificationNotification()` override that sends `VerifyEmailApi`
(a signed API URL instead of a Blade route).

### Sanctum

The API stores a `session_id` on `personal_access_tokens` so access tokens can
be tied to an `AuthSession`. Make sure the added column migration runs and that
your `PersonalAccessToken` model allows it (the default model does; the column
is filled via `forceFill`).

## 4. Migrations (order matters)

```
2026_09_17_052337_auth_sessions_table
2026_09_17_052338_refresh_tokens_table
2026_09_17_052339_add_session_to_personal_access_tokens_table
2026_09_17_052340_students_table
2026_09_17_052341_change_logs_table
2026_09_17_052342_idempotency_keys_table
2026_09_17_052343_sync_cursors_table
2026_09_17_065222_add_completed_at_to_idempotency_keys_table
2026_09_17_065452_split_sync_cursor_into_pulled_and_acknowledged
```

`users.id` is assumed to be an integer/bigint (Laravel default). The `user_id`
columns are `foreignId` to match. All synchronizable resource ids are ULIDs.

## 5. Environment variables

```dotenv
API_VERSION=v1

ACCESS_TOKEN_TTL=15
REFRESH_TOKEN_TTL=20160
REFRESH_TOKEN_REUSE_POLICY=revoke_family
TWO_FACTOR_CHALLENGE_TTL=5

SYNC_PULL_BATCH_SIZE=200
SYNC_PUSH_BATCH_SIZE=100
SYNC_MAX_OPERATIONS_PER_PUSH=200
SYNC_CHANGE_LOG_RETENTION_DAYS=30
SYNC_IDEMPOTENCY_RETENTION_DAYS=30

EMAIL_VERIFICATION_ENABLED=true
EMAIL_VERIFICATION_ENFORCE=false
EMAIL_VERIFICATION_EXPIRE_MINUTES=60
# EMAIL_VERIFICATION_REDIRECT_URL=https://app.example.com/verified

RATE_LIMIT_REGISTER=5,15
RATE_LIMIT_LOGIN=10,1
RATE_LIMIT_REFRESH=60,1
RATE_LIMIT_PASSWORD_RESET=5,15
RATE_LIMIT_PASSWORD_CONFIRMATION=10,1
RATE_LIMIT_TWO_FACTOR=10,1
RATE_LIMIT_API=240,1
RATE_LIMIT_SYNC=120,1
RATE_LIMIT_EMAIL_VERIFICATION=3,1

REVOKE_SESSIONS_ON_PASSWORD_CHANGE=true
REVOKE_SESSIONS_ON_PASSWORD_RESET=true

API_DEFAULT_PER_PAGE=20
API_MAX_PER_PAGE=100
```

All have defaults in `config/api.php`, so the API works without setting any.

## 6. Dropping Jetstream / Fortify (optional)

The v1 API does not use Jetstream or Fortify. If you remove them:

- Delete the Jetstream/Fortify service providers, config, routes and views.
- Add `pragmarx/google2fa` directly (it was pulled in by Fortify).
- Keep Laravel's password broker (`config/auth.php` passwords) and the
  `ResetPassword` notification for `/auth/forgot-password`.
- The legacy `Api\TwoFactorChallengeController` and `ConfirmPassword` middleware
  exist only for the unversioned endpoints; delete them together with the legacy
  routes if you no longer support those clients.

## 7. Verify

```bash
php artisan route:list --path=api/v1
php artisan migrate
php artisan test --compact tests/Feature/Api/V1 tests/Feature/Sync
php artisan schedule:list          # shows sync:prune-change-log
```

`ApiV1Helpers` registers users, logs in and authenticates requests. Note the
`forgetAuthGuard()` helper: within a single test the `auth:sanctum` guard
memoizes the resolved user, so call it before asserting that a revoked token
returns `401`.
