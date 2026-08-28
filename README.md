# REST API Usage Guide

This document provides instructions for using the API with client tools like **Bruno**, **Postman**, or **Insomnia**.

---

## Base URL

```
http://localhost:8000/api
```

Replace with your actual application URL in production.

---

## Authentication

This API uses **Laravel Sanctum** for token-based authentication.

### How It Works

1. **Register** or **Login** to receive a Bearer token.
2. Include the token in the `Authorization` header for all authenticated requests.
3. The token is a plain-text string (e.g., `1|abc123...`).

### Header Format

```
Authorization: Bearer YOUR_TOKEN_HERE
```

### Setting It Up in Your Client

**Postman:**
1. Go to the **Authorization** tab.
2. Select **Bearer Token** from the Type dropdown.
3. Paste the token value in the **Token** field.

**Bruno:**
1. In your request, go to the **Auth** tab.
2. Select **Bearer Token**.
3. Set the token variable.

**Insomnia:**
1. Go to **Auth** tab > **Bearer Token**.
2. Paste the token.

---

## Quick Start Workflow

Follow these steps in order to get started:

```
1. POST /api/register        → Get your token
2. GET  /api/user             → Verify your identity
3. Use the token for all other requests
```

For users with 2FA enabled:

```
1. POST /api/login            → Receive two_factor_token
2. POST /api/two-factor-challenge → Exchange for real token
3. Use the real token for all other requests
```

---

## Endpoints

### Authentication

---

#### Register

Create a new user account.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/register` |
| Auth | None |

**Request Body (JSON):**

```json
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePass123!",
    "password_confirmation": "SecurePass123!"
}
```

**Field Rules:**

| Field | Required | Rules |
|-------|----------|-------|
| `name` | Yes | String, max 255 characters |
| `email` | Yes | Valid email, max 255, must be unique |
| `password` | Yes | Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 symbol, confirmed |
| `password_confirmation` | Yes | Must match `password` |

**Success Response — `201 Created`:**

```json
{
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "email_verified_at": null,
        "two_factor_secret": null,
        "two_factor_confirmed_at": null,
        "current_team_id": null,
        "profile_photo_path": null,
        "created_at": "2026-08-28T10:00:00.000000Z",
        "updated_at": "2026-08-28T10:00:00.000000Z",
        "profile_photo_url": "https://ui-avatars.com/api/?name=John+Doe&color=7F9CF5&background=F1F5F9"
    },
    "token": "1|abc123def456..."
}
```

**Error Response — `422 Unprocessable Entity`:**

```json
{
    "errors": {
        "email": ["The email has already been taken."],
        "password": ["The password confirmation does not match."]
    }
}
```

---

#### Login

Authenticate and receive an access token.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/login` |
| Auth | None |

**Request Body (JSON):**

```json
{
    "email": "john@example.com",
    "password": "SecurePass123!"
}
```

**Success Response (no 2FA) — `200 OK`:**

```json
{
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
    },
    "token": "1|abc123def456..."
}
```

**Response When 2FA Is Enabled — `200 OK`:**

```json
{
    "message": "Two-factor authentication is required.",
    "two_factor_required": true,
    "two_factor_token": "2|xyz789..."
}
```

> Save the `two_factor_token`. You will need it to complete login via the two-factor challenge endpoint.

**Error Response — `401 Unauthorized`:**

```json
{
    "message": "The provided credentials do not match our records."
}
```

---

#### Two-Factor Challenge

Complete login after receiving a `two_factor_token`. Provide either a TOTP `code` or a `recovery_code`.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/two-factor-challenge` |
| Auth | None (uses `two_factor_token` in body) |

**Request Body — Using TOTP Code (JSON):**

```json
{
    "two_factor_token": "2|xyz789...",
    "code": "123456"
}
```

**Request Body — Using Recovery Code (JSON):**

```json
{
    "two_factor_token": "2|xyz789...",
    "recovery_code": "AB12CD34"
}
```

**Field Rules:**

| Field | Required | Rules |
|-------|----------|-------|
| `two_factor_token` | Yes | The token from the login response |
| `code` | When no `recovery_code` | Exactly 6 digits |
| `recovery_code` | When no `code` | String |

**Success Response — `200 OK`:**

```json
{
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
    },
    "token": "3|abc789def012..."
}
```

> The returned `token` is your real authentication token. Use it in the `Authorization` header.

**Error Response — `422 Unprocessable Entity`:**

```json
{
    "message": "The provided two-factor authentication code is invalid."
}
```

---

#### Logout

Revoke the current access token.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/logout` |
| Auth | Bearer Token |

**Request Body:** None

**Success Response — `200 OK`:**

```json
{
    "message": "Logged out."
}
```

---

### Profile

---

#### Get Profile

Return the authenticated user's profile.

| Field | Value |
|-------|-------|
| Method | `GET` |
| URL | `/api/user` |
| Auth | Bearer Token |

**Request Body:** None

**Success Response — `200 OK`:**

```json
{
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "email_verified_at": "2026-08-28T10:00:00.000000Z",
    "two_factor_secret": null,
    "two_factor_confirmed_at": null,
    "current_team_id": null,
    "profile_photo_path": null,
    "created_at": "2026-08-28T10:00:00.000000Z",
    "updated_at": "2026-08-28T10:00:00.000000Z",
    "profile_photo_url": "https://ui-avatars.com/api/?name=John+Doe&color=7F9CF5&background=F1F5F9"
}
```

---

#### Update Profile

Update the authenticated user's name and/or email.

| Field | Value |
|-------|-------|
| Method | `PUT` |
| URL | `/api/user/profile-information` |
| Auth | Bearer Token |

**Request Body (JSON):**

```json
{
    "name": "Jane Doe",
    "email": "jane@example.com"
}
```

**Field Rules:**

| Field | Required | Rules |
|-------|----------|-------|
| `name` | Yes | String, max 255 |
| `email` | Yes | Valid email, max 255, unique (ignores current user) |

**Success Response — `200 OK`:**

```json
{
    "id": 1,
    "name": "Jane Doe",
    "email": "jane@example.com",
    "email_verified_at": "2026-08-28T10:00:00.000000Z",
    "two_factor_secret": null,
    "two_factor_confirmed_at": null,
    "current_team_id": null,
    "profile_photo_path": null,
    "created_at": "2026-08-28T10:00:00.000000Z",
    "updated_at": "2026-08-28T10:05:00.000000Z",
    "profile_photo_url": "https://ui-avatars.com/api/?name=Jane+Doe&color=7F9CF5&background=F1F5F9"
}
```

---

#### Update Password

Change the authenticated user's password.

| Field | Value |
|-------|-------|
| Method | `PUT` |
| URL | `/api/user/password` |
| Auth | Bearer Token |

**Request Body (JSON):**

```json
{
    "current_password": "OldPass123!",
    "password": "NewPass456!",
    "password_confirmation": "NewPass456!"
}
```

**Field Rules:**

| Field | Required | Rules |
|-------|----------|-------|
| `current_password` | Yes | Must match the user's current password |
| `password` | Yes | Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 symbol, confirmed |
| `password_confirmation` | Yes | Must match `password` |

**Success Response — `200 OK`:**

```json
{
    "message": "Password updated."
}
```

**Error Response — `422 Unprocessable Entity`:**

```json
{
    "errors": {
        "current_password": ["The provided password does not match your current password."]
    }
}
```

---

#### Delete Account

Permanently delete the authenticated user's account. This action is irreversible.

| Field | Value |
|-------|-------|
| Method | `DELETE` |
| URL | `/api/user` |
| Auth | Bearer Token |

**Request Body (JSON):**

```json
{
    "password": "SecurePass123!"
}
```

**Field Rules:**

| Field | Required | Rules |
|-------|----------|-------|
| `password` | Yes | Must match the user's current password |

**Success Response — `200 OK`:**

```json
{
    "message": "Account deleted."
}
```

**Error Response — `422 Unprocessable Entity`:**

```json
{
    "errors": {
        "password": ["The password is incorrect."]
    }
}
```

---

### Password Reset

---

#### Forgot Password

Send a password reset link to the user's email.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/forgot-password` |
| Auth | None |

**Request Body (JSON):**

```json
{
    "email": "john@example.com"
}
```

**Success Response — `200 OK`:**

```json
{
    "message": "Password reset link sent."
}
```

> The response is the same whether the email exists or not, to prevent email enumeration.

**Error Response — `422 Unprocessable Entity`:**

```json
{
    "errors": {
        "email": ["The email field is required."]
    }
}
```

---

#### Reset Password

Reset the user's password using the token from the reset link.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/reset-password` |
| Auth | None |

**Request Body (JSON):**

```json
{
    "token": "abc123tokenfromemail...",
    "email": "john@example.com",
    "password": "NewPass123!",
    "password_confirmation": "NewPass123!"
}
```

**Field Rules:**

| Field | Required | Rules |
|-------|----------|-------|
| `token` | Yes | The reset token from the email link |
| `email` | Yes | The email the reset was sent to |
| `password` | Yes | Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 symbol, confirmed |
| `password_confirmation` | Yes | Must match `password` |

**Success Response — `200 OK`:**

```json
{
    "message": "Password has been reset."
}
```

**Error Response — `422 Unprocessable Entity`:**

```json
{
    "message": "Invalid or expired reset token."
}
```

---

### Password Confirmation

---

#### Confirm Password

Confirm the user's password for sensitive operations (e.g., enabling 2FA). The confirmation is valid for 3 hours.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/user/confirm-password` |
| Auth | Bearer Token |

**Request Body (JSON):**

```json
{
    "password": "SecurePass123!"
}
```

**Success Response — `200 OK`:**

```json
{
    "message": "Password confirmed."
}
```

**Error Response — `422 Unprocessable Entity`:**

```json
{
    "errors": {
        "password": ["The password is incorrect."]
    }
}
```

---

### Two-Factor Authentication

---

#### Enable 2FA

Generate a new 2FA secret, recovery codes, and QR code URL. Requires prior password confirmation.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/two-factor-enable` |
| Auth | Bearer Token + Password Confirmation |

**Prerequisite:** You must call `/api/user/confirm-password` first. The confirmation is valid for 3 hours.

**Request Body:** None

**Success Response — `200 OK`:**

```json
{
    "secret": "JBSWY3DPEHPK3PXP",
    "recovery_codes": [
        "AB12CD34",
        "EF56GH78",
        "IJ90KL12",
        "MN34OP56",
        "QR78ST90",
        "UV12WX34",
        "YZ56AB78",
        "CD90EF12"
    ],
    "qr_code": "otpauth://totp/Laravel:john@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Laravel"
}
```

> **Next step:** Scan the `qr_code` URL with an authenticator app (Google Authenticator, Authy, etc.), then call `/api/two-factor-confirm` with the 6-digit code to activate 2FA.

**Error Response — `403 Forbidden`:**

```json
{
    "message": "Password confirmation required."
}
```

---

#### Confirm 2FA

Verify a TOTP code to activate two-factor authentication.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/two-factor-confirm` |
| Auth | Bearer Token |

**Request Body (JSON):**

```json
{
    "code": "123456"
}
```

**Field Rules:**

| Field | Required | Rules |
|-------|----------|-------|
| `code` | Yes | Exactly 6 digits from your authenticator app |

**Success Response — `200 OK`:**

```json
{
    "message": "Two-factor authentication confirmed."
}
```

**Error Response — `422 Unprocessable Entity`:**

```json
{
    "message": "The provided two-factor authentication code is invalid."
}
```

---

#### Disable 2FA

Turn off two-factor authentication.

| Field | Value |
|-------|-------|
| Method | `DELETE` |
| URL | `/api/two-factor-disable` |
| Auth | Bearer Token |

**Request Body:** None

**Success Response — `200 OK`:**

```json
{
    "message": "Two-factor authentication disabled."
}
```

---

#### Regenerate Recovery Codes

Generate new recovery codes. The old codes become invalid.

| Field | Value |
|-------|-------|
| Method | `PUT` |
| URL | `/api/two-factor-recovery-codes` |
| Auth | Bearer Token |

**Request Body:** None

**Success Response — `200 OK`:**

```json
{
    "recovery_codes": [
        "GH34IJ56",
        "KL78MN90",
        "OP12QR34",
        "ST56UV78",
        "WX90YZ12",
        "AB34CD56",
        "EF78GH90",
        "IJ12KL34"
    ]
}
```

**Error Response — `422 Unprocessable Entity`:**

```json
{
    "message": "Two-factor authentication has not been enabled."
}
```

---

### API Tokens

Manage personal access tokens (Sanctum). These tokens can be used to authenticate third-party integrations.

---

#### List Tokens

Return all tokens for the authenticated user.

| Field | Value |
|-------|-------|
| Method | `GET` |
| URL | `/api/api-tokens` |
| Auth | Bearer Token |

**Request Body:** None

**Success Response — `200 OK`:**

```json
[
    {
        "id": 1,
        "tokenable_type": "App\\Models\\User",
        "tokenable_id": 1,
        "name": "mobile-app",
        "token": "abc123...",
        "abilities": ["read", "create"],
        "last_used_at": "2026-08-28T12:00:00.000000Z",
        "expires_at": null,
        "created_at": "2026-08-28T10:00:00.000000Z",
        "updated_at": "2026-08-28T10:00:00.000000Z"
    }
]
```

> Note: The `token` field is hashed and cannot be read. Only the `plain_text_token` returned at creation time is usable.

---

#### Create Token

Create a new personal access token.

| Field | Value |
|-------|-------|
| Method | `POST` |
| URL | `/api/api-tokens` |
| Auth | Bearer Token |

**Request Body (JSON):**

```json
{
    "name": "my-integration",
    "permissions": ["read", "create"],
    "expires_at": "2026-12-31"
}
```

**Field Rules:**

| Field | Required | Rules |
|-------|----------|-------|
| `name` | Yes | String, max 255 |
| `permissions` | Yes | Array of: `create`, `read`, `update`, `delete` |
| `expires_at` | No | ISO 8601 date in the future |

**Success Response — `201 Created`:**

```json
{
    "token": {
        "id": 2,
        "name": "my-integration",
        "abilities": ["read", "create"],
        "last_used_at": null,
        "expires_at": "2026-12-31T00:00:00.000000Z",
        "created_at": "2026-08-28T10:00:00.000000Z"
    },
    "plain_text_token": "2|abc123def456..."
}
```

> **Important:** The `plain_text_token` is shown only once. Copy and store it securely. It cannot be retrieved later.

---

#### Update Token Permissions

Change the abilities of an existing token.

| Field | Value |
|-------|-------|
| Method | `PUT` |
| URL | `/api/api-tokens/{tokenId}` |
| Auth | Bearer Token |

**Request Body (JSON):**

```json
{
    "permissions": ["read"]
}
```

**Field Rules:**

| Field | Required | Rules |
|-------|----------|-------|
| `permissions` | Yes | Array of: `create`, `read`, `update`, `delete` |

**Success Response — `200 OK`:**

```json
{
    "id": 2,
    "tokenable_type": "App\\Models\\User",
    "tokenable_id": 1,
    "name": "my-integration",
    "token": "abc123...",
    "abilities": ["read"],
    "last_used_at": null,
    "expires_at": "2026-12-31T00:00:00.000000Z",
    "created_at": "2026-08-28T10:00:00.000000Z",
    "updated_at": "2026-08-28T10:05:00.000000Z"
}
```

**Error Response — `404 Not Found`:**

```json
{
    "message": "Token not found."
}
```

---

#### Delete Token

Revoke and delete an API token.

| Field | Value |
|-------|-------|
| Method | `DELETE` |
| URL | `/api/api-tokens/{tokenId}` |
| Auth | Bearer Token |

**Request Body:** None

**Success Response — `200 OK`:**

```json
{
    "message": "Token deleted."
}
```

**Error Response — `404 Not Found`:**

```json
{
    "message": "Token not found."
}
```

---

## Common Error Responses

All errors follow this format:

```json
{
    "message": "Error description.",
    "errors": {
        "field": ["Specific error message."]
    }
}
```

| Status Code | Meaning |
|-------------|---------|
| `200` | Success |
| `201` | Created |
| `401` | Unauthenticated (no token, invalid token, or bad credentials) |
| `403` | Forbidden (password confirmation required) |
| `404` | Resource not found |
| `422` | Validation failed |
| `500` | Server error |

---

## Example Bruno Collection

Bruno uses `.bru` files. Below is a minimal collection structure:

### Folder Structure

```
collection/
  auth/
    register.bru
    login.bru
    logout.bru
  profile/
    get-user.bru
    update-profile.bru
    update-password.bru
    delete-account.bru
  password-reset/
    forgot-password.bru
    reset-password.bru
  two-factor/
    confirm-password.bru
    enable-2fa.bru
    confirm-2fa.bru
    disable-2fa.bru
    regenerate-recovery-codes.bru
  tokens/
    list-tokens.bru
    create-token.bru
    update-token.bru
    delete-token.bru
```

### Example: `register.bru`

```bru
meta {
  name: Register
  type: http
  seq: 1
}

post {
  url: {{baseUrl}}/api/register
  body: json
  auth: none
}

body:json {
  {
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePass123!",
    "password_confirmation": "SecurePass123!"
  }
}

script:post-response {
  if (res.status === 201) {
    bru.setVar("token", res.body.token);
    bru.setEnvVar("authToken", res.body.token);
  }
}
```

### Example: `login.bru`

```bru
meta {
  name: Login
  type: http
  seq: 2
}

post {
  url: {{baseUrl}}/api/login
  body: json
  auth: none
}

body:json {
  {
    "email": "john@example.com",
    "password": "SecurePass123!"
  }
}

script:post-response {
  if (res.status === 200 && !res.body.two_factor_required) {
    bru.setEnvVar("authToken", res.body.token);
  }
  if (res.body.two_factor_required) {
    bru.setEnvVar("twoFactorToken", res.body.two_factor_token);
  }
}
```

### Example: `get-user.bru`

```bru
meta {
  name: Get User Profile
  type: http
  seq: 3
}

get {
  url: {{baseUrl}}/api/user
  auth: bearer
}

auth:bearer {
  token: {{authToken}}
}
```

---

## Environment Variables (Bruno/Postman)

Set these variables in your environment for easy reuse:

| Variable | Example Value | Description |
|----------|---------------|-------------|
| `baseUrl` | `http://localhost:8000` | Your application URL |
| `authToken` | `1\|abc123...` | Bearer token from login/register |
| `twoFactorToken` | `2\|xyz789...` | Temporary token for 2FA challenge |
| `tokenId` | `1` | ID of a token for update/delete operations |

---

## Postman Collection (Importable JSON)

Create a new collection in Postman and add requests using the details above. Alternatively, import this minimal JSON structure:

```json
{
    "info": {
        "name": "Laravel REST API",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
    },
    "auth": {
        "type": "bearer",
        "bearer": [
            {
                "key": "token",
                "value": "{{authToken}}",
                "type": "string"
            }
        ]
    },
    "variable": [
        {
            "key": "baseUrl",
            "value": "http://localhost:8000"
        }
    ],
    "item": [
        {
            "name": "Register",
            "request": {
                "method": "POST",
                "url": "{{baseUrl}}/api/register",
                "header": [
                    { "key": "Content-Type", "value": "application/json" }
                ],
                "body": {
                    "mode": "raw",
                    "raw": "{\"name\":\"John Doe\",\"email\":\"john@example.com\",\"password\":\"SecurePass123!\",\"password_confirmation\":\"SecurePass123!\"}"
                }
            }
        },
        {
            "name": "Login",
            "request": {
                "method": "POST",
                "url": "{{baseUrl}}/api/login",
                "header": [
                    { "key": "Content-Type", "value": "application/json" }
                ],
                "body": {
                    "mode": "raw",
                    "raw": "{\"email\":\"john@example.com\",\"password\":\"SecurePass123!\"}"
                }
            }
        },
        {
            "name": "Logout",
            "request": {
                "method": "POST",
                "url": "{{baseUrl}}/api/logout"
            }
        },
        {
            "name": "Get User",
            "request": {
                "method": "GET",
                "url": "{{baseUrl}}/api/user"
            }
        },
        {
            "name": "Update Profile",
            "request": {
                "method": "PUT",
                "url": "{{baseUrl}}/api/user/profile-information",
                "header": [
                    { "key": "Content-Type", "value": "application/json" }
                ],
                "body": {
                    "mode": "raw",
                    "raw": "{\"name\":\"Jane Doe\",\"email\":\"jane@example.com\"}"
                }
            }
        },
        {
            "name": "Update Password",
            "request": {
                "method": "PUT",
                "url": "{{baseUrl}}/api/user/password",
                "header": [
                    { "key": "Content-Type", "value": "application/json" }
                ],
                "body": {
                    "mode": "raw",
                    "raw": "{\"current_password\":\"SecurePass123!\",\"password\":\"NewPass456!\",\"password_confirmation\":\"NewPass456!\"}"
                }
            }
        },
        {
            "name": "Delete Account",
            "request": {
                "method": "DELETE",
                "url": "{{baseUrl}}/api/user",
                "header": [
                    { "key": "Content-Type", "value": "application/json" }
                ],
                "body": {
                    "mode": "raw",
                    "raw": "{\"password\":\"SecurePass123!\"}"
                }
            }
        }
    ]
}
```

---

## Password Requirements

All password fields that create or change a password follow these rules:

- Minimum **8 characters**
- At least **1 uppercase** letter
- At least **1 lowercase** letter
- At least **1 number**
- At least **1 symbol** (e.g., `!@#$%^&*`)
- Must be confirmed with `password_confirmation` field

---

## Tips

1. **Token storage:** Always store tokens securely. Never commit them to version control.
2. **Token expiration:** Tokens created without `expires_at` never expire. Set an expiration for production use.
3. **2FA flow:** Always call `confirm-password` before `two-factor-enable`. The confirmation lasts 3 hours.
4. **Recovery codes:** Each recovery code can only be used once. Regenerate them if you suspect they are compromised.
5. **Rate limiting:** Login is throttled to 5 attempts per minute per email+IP combination.
6. **Token revocation:** Calling login or register automatically revokes previous tokens for that user.
