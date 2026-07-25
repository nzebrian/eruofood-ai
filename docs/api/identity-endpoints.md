# Identity & Access — API Endpoints

Base URL: `/api/v1`. All requests and responses are JSON. Authenticated
endpoints require `Authorization: Bearer <access_token>`. The machine-readable
contract is [`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

Responses use the standard envelope: success is `{ "data": … }`, errors are
`{ "error": { "code", "message" } }`.

## Authentication (public)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `POST /auth/register` | Register | Creates an account (name, email, password + confirmation), sends a verification email, and returns the user + tokens (auto-login). `409` if the email exists. |
| `POST /auth/login` | Log in | Email + password. Returns `{ user, tokens }`, **or** `{ two_factor_required: true, challenge_token }` when 2FA is enabled. `401` on bad credentials. |
| `POST /auth/login/two-factor` | Complete 2FA | Exchanges `challenge_token` + TOTP `code` for tokens. |
| `POST /auth/login/social` | Social sign-in | `provider` (`google` \| `apple`) + `id_token`. Verifies the provider token, links or creates the user, returns tokens. Apple is architecture-ready (disabled by default). |
| `POST /auth/refresh` | Refresh | Exchanges a `refresh_token` for a new access token and a **rotated** refresh token. |
| `POST /auth/logout` | Log out | Revokes the presented `refresh_token` (ends that session). |
| `POST /auth/email/verify` | Verify email | Consumes the emailed `uid` + `token` to mark the address verified. |
| `POST /auth/password/forgot` | Forgot password | Emails a reset link. Always `202` (no account enumeration). |
| `POST /auth/password/reset` | Reset password | Consumes the emailed `token`, sets a new password, and revokes all sessions. |

## Profile — current user (authenticated, prefix `/me`)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /me` | Get profile | Returns the authenticated user's profile (roles, permissions, 2FA status, preferences). |
| `PUT /me` | Update profile | Updates `name` and optional `phone` (E.164). |
| `DELETE /me` | Delete account | Soft-deletes the account. |
| `PUT /me/password` | Change password | Requires `current_password`; sets a new password. |
| `PUT /me/preferences` | Update preferences | Stores a free-form `preferences` JSON object. |
| `POST /me/avatar` | Upload avatar | `multipart/form-data` image; stored on S3, returns the updated profile. |
| `POST /auth/email/resend` | Resend verification | Re-sends the verification email to the current user. |

## Two-factor (authenticated)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `POST /me/two-factor/enable` | Begin enrolment | Returns `secret`, `provisioning_uri` (for a QR code), and one-time `recovery_codes`. Pending until confirmed. |
| `POST /me/two-factor/confirm` | Confirm | Verifies the first TOTP `code` and activates 2FA. |
| `DELETE /me/two-factor` | Disable | Turns off 2FA after re-verifying the account `password`. |

## Sessions (authenticated)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /me/sessions` | List sessions | Active sessions/devices (ip, user agent, last used). |
| `DELETE /me/sessions/{sessionId}` | Revoke session | Revokes a specific session's refresh token. |

## Admin — RBAC (authenticated, `role:admin`, prefix `/admin`)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /admin/users` | List users | Paginated (`page`, `per_page`). Admin only. |
| `POST /admin/users/{userId}/roles` | Assign role | Grants `role` (`admin`\|`moderator`\|`user`). Audited. |
| `DELETE /admin/users/{userId}/roles` | Revoke role | Removes a role. Audited. |

## Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `VALIDATION_FAILED` / Laravel 422 | 422 | Request failed validation. |
| `INVALID_CREDENTIALS` | 401 | Wrong email/password or invalid token. |
| `INVALID_TWO_FACTOR_CODE` | 401 | Bad/expired 2FA code or challenge. |
| `UNAUTHENTICATED` | 401 | Missing/invalid access token. |
| `FORBIDDEN` | 403 | Authenticated but lacks the role. |
| `ACCOUNT_SUSPENDED` | 403 | The account is suspended. |
| `USER_NOT_FOUND` | 404 | No such user. |
| `EMAIL_ALREADY_REGISTERED` | 409 | Email already in use. |
| `INVALID_ARGUMENT` | 422 | A value object rejected the input (e.g. bad phone). |

## Rate limiting

Sensitive endpoints are throttled (register/login/2FA/forgot/reset), e.g.
`register` at 6/min and `login` at 10/min per IP, via Laravel's `throttle`
middleware (Redis-backed).
