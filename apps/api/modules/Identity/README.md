# Identity & Access Module (`EruoFood\Identity`)

The Identity & Access bounded context — authentication, user management, roles &
permissions, sessions, 2FA, and audit logging. Built with Clean Architecture,
DDD, the Repository Pattern, a Service Layer, and Dependency Injection.

> **Milestone 2.** No recipes, ordering, or AI here — this module owns *who a
> user is* and *how they authenticate*, nothing else.

## Folder structure & what each layer holds

```
modules/Identity/src/
├── Domain/                     # Layer 1 — pure PHP, no framework
│   ├── User/
│   │   ├── User.php            # Aggregate root: identity, credentials, roles, 2FA
│   │   ├── UserStatus.php      # active | suspended
│   │   ├── TwoFactorSettings.php  # immutable 2FA state VO
│   │   └── UserRepository.php  # repository PORT (interface)
│   ├── ValueObject/            # Email, PhoneNumber, FullName, HashedPassword, UserId
│   ├── Role/                   # Role + Permission enums (RBAC mapping)
│   ├── Event/                  # UserRegistered, EmailVerified, PasswordChanged, TwoFactorEnabled
│   └── Exception/              # Domain exceptions (mapped to HTTP by the app shell)
├── Application/                # Layer 2 — use cases, framework-agnostic
│   ├── Service/                # SERVICE LAYER: Registration, Authentication,
│   │                          #   Password, TwoFactor, Profile, Session,
│   │                          #   UserAdmin, Token, UserPresenter
│   ├── Port/                   # Interfaces the services depend on (hasher, token
│   │                          #   issuer, refresh manager, 2FA, mailer, storage,
│   │                          #   one-time tokens, social auth, audit, oauth)
│   └── DTO/                    # Commands/results: AuthResult, AuthTokens, views…
├── Infrastructure/            # Layer 4 — adapters (implement the ports)
│   ├── Persistence/Eloquent/   # Models + EloquentUserRepository + OAuth accounts
│   ├── Persistence/Migration/  # Module-owned migrations (identity_* tables)
│   ├── Auth/                   # Argon2 hasher, JWT issuer, refresh manager,
│   │                          #   google2fa, one-time tokens, login challenges,
│   │                          #   Social/ (Google, Apple)
│   ├── Mail/                   # Mailables + blade views + notifier
│   ├── Storage/                # S3 avatar storage
│   ├── Audit/                  # Database audit recorder
│   ├── Contract/               # UserDirectory adapter (public API impl)
│   └── Provider/               # IdentityServiceProvider — the composition root
├── Interface/                 # Layer 3 — delivery mechanisms
│   └── Http/
│       ├── Controller/         # Thin controllers (Auth, Profile, TwoFactor,
│       │                      #   Session, EmailVerification, PasswordReset, Admin)
│       ├── Request/            # FormRequests (validation)
│       ├── Resource/           # API resources (User, Session)
│       ├── Middleware/         # JwtAuthenticate, EnsureRole
│       ├── Concerns/           # ResolvesAuthUser, BuildsAuthResponse
│       └── routes.php          # Module routes (mounted at /api/v1)
├── Contracts/                 # PUBLIC API for other modules (UserDirectory, PublicUser)
└── tests/                     # Unit (domain) + Feature (HTTP) tests
```

## Key design decisions

1. **Aggregate-centric domain.** `User` is the single consistency boundary; all
   state changes go through behaviour methods that enforce invariants and record
   domain events. Value objects (`Email`, `PhoneNumber`, `HashedPassword`) make
   invalid states unrepresentable.
2. **Service Layer over the domain.** Each use case group is an application
   service (`AuthenticationService`, `RegistrationService`, …). Controllers are
   thin; they translate HTTP ↔ service calls only.
3. **Ports & adapters (Dependency Inversion).** The application depends on
   interfaces (`PasswordHasher`, `TokenIssuer`, `RefreshTokenManager`, …); the
   infrastructure supplies implementations, wired in `IdentityServiceProvider`.
   This is what makes Apple/phone auth "architecture-ready" — new adapters, no
   application changes.
4. **Stateless JWT access tokens + rotating refresh tokens.** Access tokens are
   short-lived signed JWTs (verification in middleware, no DB hit). Sessions are
   refresh tokens stored **hashed**; each is a device/session, enabling session
   listing and per-session revocation. Rotation on every refresh limits replay.
5. **RBAC via enums.** `Role`→`Permission` mapping lives in the domain;
   authorization is expressed in permissions so policies survive role changes.
   Roles are stored as a JSON column (no pivot) — simple and extractable.
6. **Argon2id password hashing** with self-describing parameters.
7. **Security-first flows.** Password reset and forgot-password never reveal
   account existence; reset invalidates all sessions; one-time tokens are stored
   hashed with a TTL; 2FA secrets & recovery codes are encrypted at rest.
8. **Soft deletes + audit trail.** Users are soft-deleted; security-relevant
   actions append immutable `identity_audit_logs` rows (to move to the Audit
   context later).
9. **No cross-context FKs.** Tables reference users by id only (soft references),
   preserving module autonomy per MASTER_PLAN §5.
10. **Published contract.** Other modules read identity solely through
    `Contracts\UserDirectory` — never the internal domain/infrastructure.

## Dependencies added

- `firebase/php-jwt` — JWT signing/verification.
- `pragmarx/google2fa` — TOTP two-factor.
