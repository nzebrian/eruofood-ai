# ADR-0002: JWT access tokens + rotating refresh tokens for authentication

- **Status:** Accepted
- **Date:** 2026-07-25
- **Deciders:** Engineering, Security

## Context

The platform serves a React web app, a Flutter mobile app, and (later)
third-party clients. We need stateless, horizontally-scalable authentication
that also supports session listing/revocation, 2FA, and social sign-in.

## Decision

- **Access token:** a short-lived signed **JWT** (HS256, ~15 min). Verified in
  middleware with no database hit, so the app tier stays stateless and scalable.
- **Refresh token:** an opaque, high-entropy string, stored **hashed** in
  `identity_refresh_tokens`. Each row is a **session/device**. Refresh **rotates**
  the token on every use and slides the expiry. This enables session listing,
  per-session revocation, and limits replay of a leaked refresh token.
- **2FA:** when enabled, password login returns a short-lived **challenge token**
  (held in the cache) instead of access tokens; the client completes it with a
  TOTP code.
- **Social sign-in** is verified through a provider-agnostic `SocialAuthenticator`
  port (Google implemented; Apple architecture-ready).

## Consequences

- **Positive:** stateless verification, no shared session store on the hot path,
  first-class session management, provider-agnostic social login.
- **Negative / trade-offs:** access tokens cannot be revoked before expiry (kept
  short to bound the window; a jti blocklist in Redis can be added if needed).
  Refresh-token rotation requires clients to store the newest token.
- **Security controls:** refresh tokens hashed at rest; reset/forgot flows avoid
  account enumeration; password reset revokes all sessions; 2FA secrets and
  recovery codes encrypted at rest; sensitive endpoints rate-limited.

## Alternatives considered

- **Server-side sessions (stateful cookies)** — rejected for the mobile/API
  surface and horizontal-scaling goals (shared session store on every request).
- **Long-lived JWTs without refresh** — rejected: no revocation, larger blast
  radius on leak.
- **Opaque access tokens (DB lookup per request)** — rejected: defeats the
  stateless, low-latency goal at scale.
