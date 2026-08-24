# A1.4 — JWT Authentication, Roles & Anti-Brute-Force

Status: Approved (autonomous — see note below)
Author: Claude, on Serge's explicit authorization to proceed without waiting for review
Date: 2026-08-24
Plan reference: A1.4 (Phase A1 — Fondations), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 5.2.a, 5.4

## Note on process

For A1.3, every design decision was made through one-question-at-a-time dialogue with Serge.
For A1.4, Serge explicitly authorized proceeding autonomously ("je te laisse faire les bons
choix pour qu'il n'y ait aucun blocage") before going to bed, specifically so this task would
not be blocked on his availability. This spec documents every decision made under that
authorization, with rationale, exactly as the A1.3 spec did — so it can be reviewed
after the fact instead of before. Nothing here overrides the cahier des charges; where the
cahier des charges is silent, the decision follows the most standard, boring Symfony
convention available, not a novel design.

## Goal

Implement the authentication system described in cahier des charges 5.2.a: JWT-based login
with short-lived access tokens, a refresh token mechanism, server-side logout invalidation,
and anti-brute-force protection — enforcing the four roles already modeled on `User.role`
(`Admin`, `InternalAnalyst`, `ExternalPartner`; "Visitor" = no account, per A1.3 decision 6).

## Non-goals (out of scope for A1.4)

- Self-service registration endpoint. The plan's A1.4 livrable is "endpoints
  d'authentification opérationnels, jetons JWT + refresh token" — not account creation. The
  cahier des charges doesn't specify a registration flow for A1.4. Users are created via
  fixtures/console for now; a registration endpoint is not invented here.
- API key authentication (already modeled in `ApiKey` entity from A1.3, but wiring it into
  the security firewall is not part of A1.4's scope per the plan).
- Rate limiting on public API endpoints generally (cahier des charges 5.4 lists this as a
  Volet A requirement but the plan assigns it to A3.4, not A1.4).
- Password reset / email verification — not mentioned anywhere in the cahier des charges.

## Decisions made autonomously

1. **JWT issuance: LexikJWTAuthenticationBundle.** This is the de facto standard JWT bundle
   for Symfony, actively maintained, integrates directly with Symfony Security. No other
   library is seriously competitive for this stack. Chosen over hand-rolling JWT issuance
   because that would mean re-implementing signature verification, claim validation, and key
   management — solved problems this bundle handles correctly.
2. **Refresh tokens: GesdinetJWTRefreshTokenBundle.** The standard companion package to Lexik,
   stores refresh tokens server-side in a DB table, supports single-use rotation and
   revocation — which is exactly what's needed for "déconnexion invalide le jeton côté
   serveur" (see decision 6).
3. **Anti-brute-force: Symfony's built-in `login_throttling`** (Security component's
   RateLimiter integration), not a custom-rolled counter. It throttles by username, keyed
   with the client IP as a secondary signal, using Symfony's RateLimiter component
   (`SlidingWindow` policy). Chosen because it's already part of `symfony/security-bundle`
   (no extra dependency), is the framework-native answer to exactly this requirement, and is
   well-tested.
   - **Threshold: 5 failed attempts per 15 minutes**, then temporary lockout. The cahier des
     charges specifies "un nombre défini de tentatives" without a number; 5/15min is a common,
     reasonable default balancing security and legitimate-user friction. Documented here so
     it's a one-line config change if the team wants a different number.
4. **Password hashing: `algorithm: auto`** in `security.yaml`'s password hasher config. This
   is Symfony's own recommended setting — it picks sodium/argon2id when available, falling
   back to bcrypt otherwise. Satisfies cahier des charges 5.2.a ("hachage (bcrypt/argon2)")
   without hard-coding one specific algorithm.
5. **Role mapping, no hierarchy.** `UserRole::Admin` → `ROLE_ADMIN`, `InternalAnalyst` →
   `ROLE_INTERNAL_ANALYST`, `ExternalPartner` → `ROLE_EXTERNAL_PARTNER`; every authenticated
   user additionally gets Symfony's `ROLE_USER` (framework convention — required for the
   `IS_AUTHENTICATED_FULLY` style checks to work cleanly). **No `role_hierarchy` config is
   added** — cahier des charges 5.2.a describes the four profiles as "chacun avec un périmètre
   d'action propre" (each with its own scope), which reads as parallel, non-nested
   permissions, not a ladder where Admin inherits Analyst inherits Partner. If a future phase
   needs Admin to inherit other roles' permissions, that's an explicit decision for whoever
   owns that endpoint's access rule, not something this task should silently bake in.
6. **Logout semantics for a stateless-JWT architecture.** A signed JWT access token cannot be
   individually revoked without a server-side blocklist, which the cahier des charges doesn't
   ask for. The practical, standard interpretation of "déconnexion invalide le jeton côté
   serveur" in a JWT + refresh-token architecture (and what GesdinetJWTRefreshTokenBundle is
   built for): **logout revokes the refresh token** server-side (deletes the row from
   `refresh_tokens`), so the session cannot be renewed past the access token's own short TTL.
   The access token itself simply expires naturally within its 15-minute lifetime. This is
   documented explicitly because "invalidate token server-side" could be misread as requiring
   an access-token blocklist, which is a heavier mechanism the cahier des charges doesn't
   call for.
7. **Token lifetimes: 900s (15 min) access token, 2,592,000s (30 days) refresh token, single-
   use rotation on the refresh token.** "Courte durée" (cahier des charges) is directionally
   specified but not numerically; 15 minutes is a standard short-lived access token window.
   Single-use rotation (a new refresh token is issued and the old one invalidated on every
   refresh) is Gesdinet's recommended secure default — it limits the damage window if a
   refresh token leaks.
8. **JWT signing keys: RSA keypair, generated locally, gitignored, referenced via env vars**
   (`JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`), consistent with cahier des charges
   5.4 ("Aucun secret ... stocké en clair dans le code source"). Same pattern already used for
   `APP_SECRET`/`POSTGRES_PASSWORD` in this repo (`.env`, gitignored, `.env.example`
   documents the shape).
9. **No new entities.** `User` (with `role`) from A1.3 is reused as-is; only a new
   `RefreshToken` entity is added, owned by GesdinetJWTRefreshTokenBundle's own base class
   (not part of the cahier des charges' 8-entity pipeline-ready model — it's an
   authentication implementation detail, not business data).
10. **Endpoints added:** `POST /api/auth/login` (JSON body `{email, password}`, issues access
    + refresh token pair), `POST /api/auth/refresh` (JSON body `{refresh_token}`, issues a new
    pair), `POST /api/auth/logout` (requires a valid access token, revokes the caller's
    current refresh token). A `GET /api/auth/me` endpoint is also added, returning the
    authenticated user's `email` and `role` — needed as a concrete, testable proof that role-
    based access control actually works end-to-end, and a genuinely useful endpoint for any
    future frontend integration (not scope creep: it's the minimum surface to verify this
    task's own acceptance criteria).

## Architecture

```
POST /api/auth/login {email, password}
  → Symfony's json_login authenticator on the "login" firewall
  → UserProvider loads App\Entity\User by email (already unique, from A1.3)
  → PasswordHasher verifies passwordHash
  → LoginThrottling: 5 failed attempts / 15 min per username → 429 lockout
  → on success: LexikJWTAuthenticationBundle issues access token (15 min TTL)
  → GesdinetJWTRefreshTokenBundle issues + persists refresh token (30 days TTL, single-use)
  → response: {token, refresh_token}

POST /api/auth/refresh {refresh_token}
  → Gesdinet's refresh endpoint: validates + rotates the refresh token
  → response: {token, refresh_token} (new pair; old refresh token invalidated)

POST /api/auth/logout (Authorization: Bearer <access token>)
  → requires a valid, non-expired access token
  → revokes (deletes) the refresh token(s) belonging to the authenticated user
  → response: 204

GET /api/auth/me (Authorization: Bearer <access token>)
  → requires a valid, non-expired access token
  → response: {email, role}
```

Two firewalls in `security.yaml`:
- `login`: matches `^/api/auth/login`, stateless, `json_login` authenticator + `login_throttling`.
- `api`: matches `^/api`, stateless, `jwt` authenticator (Lexik) — protects everything else
  under `/api`, including `/api/auth/logout` and `/api/auth/me`.
- Everything outside `^/api` (none exists yet) is implicitly the `dev`/`main` firewall from
  Symfony's default config, unaffected by this change.

`access_control` rules: `/api/auth/login` and `/api/auth/refresh` are `PUBLIC_ACCESS`;
`/api/auth/logout` and `/api/auth/me` require `IS_AUTHENTICATED_FULLY`. No role-specific
`access_control` rule is added beyond that, since no business endpoint exists yet to scope by
role (A2.x work) — `GET /api/auth/me` exists precisely to give this task something concrete
to assert role-based identity against without inventing a business endpoint that belongs to a
later phase.

## Testing / validation

- Unit tests: `User` already implements the getters needed (Task adds `UserInterface` +
  `PasswordAuthenticatedUserInterface` methods — `getRoles()`, `getPassword()`,
  `getUserIdentifier()`, `eraseCredentials()` — tested directly, no DB).
- Functional tests (`WebTestCase`, real HTTP requests against the test client):
  login with valid credentials → 200 + token pair; login with wrong password → 401; login
  with unknown email → 401; 6th failed login attempt within the window → 429; refresh with a
  valid refresh token → 200 + new pair, old refresh token now rejected; `/api/auth/me` without
  a token → 401; `/api/auth/me` with a valid token → 200 + correct email/role; logout then
  reuse of the same refresh token → rejected.

## Documentation

`README.md` gains an "Authentification" section: how to generate the JWT keypair, the four
endpoints with example requests/responses, the throttling threshold, and the token lifetimes
— mirroring the "Schéma de données" section added for A1.3.
