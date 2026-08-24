# A1.4 — JWT Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Secure the API with JWT authentication (login/refresh/logout), enforce the four roles already modeled on `User.role`, and add anti-brute-force login throttling, per the approved design spec.

**Architecture:** LexikJWTAuthenticationBundle issues/verifies access tokens; GesdinetJWTRefreshTokenBundle issues/rotates/revokes refresh tokens; Symfony's built-in `login_throttling` protects the login endpoint. Two stateless firewalls (`login`, `api`) in `security.yaml`.

**Tech Stack:** Symfony 7.4 Security component, `lexik/jwt-authentication-bundle`, `gesdinet/jwt-refresh-token-bundle`, Doctrine ORM (existing `User` entity from A1.3), PHPUnit 13.3 (`WebTestCase` for functional HTTP tests).

## Global Constraints

- No self-registration endpoint — out of scope per spec decision 1 (Non-goals).
- Password hashing: `algorithm: auto` — spec decision 4.
- Role mapping only, no `role_hierarchy` — spec decision 5.
- Logout = revoke the refresh token, not an access-token blocklist — spec decision 6.
- Access token TTL 900s, refresh token TTL 2,592,000s, single-use rotation — spec decision 7.
- JWT keys: RSA keypair, gitignored, referenced via `JWT_SECRET_KEY`/`JWT_PUBLIC_KEY`/`JWT_PASSPHRASE` env vars — spec decision 8.
- Anti-brute-force: 5 failed attempts / 15 minutes per username — spec decision 3.
- Repo root for all paths: `nev-climate-data/`. All `docker compose` commands run from that root, all `php`/`composer` commands run inside the `backend` container via `docker compose exec backend ...`.

---

## Task 1: Install JWT bundles, generate keys, migrate refresh_tokens table

**Files:**
- Modify: `backend/composer.json`, `backend/config/bundles.php` (via Symfony Flex, automatic)
- Create: `backend/config/packages/lexik_jwt_authentication.yaml`, `backend/config/packages/gesdinet_jwt_refresh_token.yaml` (via Flex recipes, then reviewed/adjusted)
- Create: `backend/config/jwt/private.pem`, `backend/config/jwt/public.pem` (gitignored)
- Modify: `.env.example`, `backend/.gitignore`
- Create: `backend/migrations/VersionXXXXXXXXXXXXXX.php` (refresh_tokens table)

**Interfaces:**
- Produces: JWT issuance/verification available to the security system; a `refresh_tokens` DB table. Consumed by every later task.

- [ ] **Step 1: Require the two bundles**

Run: `docker compose exec backend composer require lexik/jwt-authentication-bundle gesdinet/jwt-refresh-token-bundle`
Expected: both packages install; Symfony Flex auto-registers them in `config/bundles.php` and drops starter config files under `config/packages/`.

- [ ] **Step 2: Review the Flex-generated config**

Open `backend/config/packages/lexik_jwt_authentication.yaml` and `backend/config/packages/gesdinet_jwt_refresh_token.yaml`. Replace their contents with:

```yaml
# backend/config/packages/lexik_jwt_authentication.yaml
lexik_jwt_authentication:
    secret_key: '%env(resolve:JWT_SECRET_KEY)%'
    public_key: '%env(resolve:JWT_PUBLIC_KEY)%'
    pass_phrase: '%env(JWT_PASSPHRASE)%'
    token_ttl: 900
```

```yaml
# backend/config/packages/gesdinet_jwt_refresh_token.yaml
gesdinet_jwt_refresh_token:
    refresh_token_class: App\Entity\RefreshToken
    ttl: 2592000
    ttl_update: false
    single_use: true
    firewall: api
```

- [ ] **Step 2b: Generate the RSA keypair**

Run:
```bash
docker compose exec backend mkdir -p config/jwt
docker compose exec backend php bin/console lexik:jwt:generate-keypair --overwrite
```
Expected: `backend/config/jwt/private.pem` and `backend/config/jwt/public.pem` are created inside the container. The command prompts for a passphrase only if `JWT_PASSPHRASE` isn't already set as an env var — set it first (Step 3) so this runs non-interactively.

- [ ] **Step 3: Add JWT env vars**

Add to `.env.example` (root):

```
# --- Authentification JWT --------------------------------------------------
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=change_me_use_a_strong_passphrase
```

Add the same three lines to the local `.env` (not tracked by git), with `JWT_PASSPHRASE` set to a real generated value (`openssl rand -hex 16`), matching the pattern already used for `APP_SECRET`/`POSTGRES_PASSWORD` in that file. Regenerate the keypair with the real passphrase:

Run: `docker compose exec backend php bin/console lexik:jwt:generate-keypair --overwrite`
Expected: no interactive prompt (passphrase read from `JWT_PASSPHRASE`), keys regenerated.

- [ ] **Step 4: Gitignore the keypair**

Add to `backend/.gitignore`:

```
###> lexik/jwt-authentication-bundle ###
/config/jwt/*.pem
###< lexik/jwt-authentication-bundle ###
```

- [ ] **Step 5: Create the `RefreshToken` entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

#[ORM\Entity]
class RefreshToken extends BaseRefreshToken
{
}
```

Save as `backend/src/Entity/RefreshToken.php`.

- [ ] **Step 6: Generate and review the migration**

Run: `docker compose exec backend php bin/console doctrine:migrations:diff --no-interaction`
Expected: a new `backend/migrations/VersionYYYYMMDDHHMMSS.php` containing `CREATE TABLE refresh_tokens (id ..., refresh_token VARCHAR(128) UNIQUE, username VARCHAR(255), valid DATETIME, ...)`. As with every migration generated in A1.3, check for and remove any spurious `CREATE SCHEMA timescaledb_information` / `_timescaledb_*` statements from `down()` if Doctrine's diff includes them again (see `docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md` for why).

- [ ] **Step 7: Apply the migration to test and dev**

Run:
```bash
docker compose exec -e APP_ENV=test backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:schema:validate
```
Expected: both `[OK]` lines.

- [ ] **Step 8: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/config/bundles.php backend/config/packages/lexik_jwt_authentication.yaml backend/config/packages/gesdinet_jwt_refresh_token.yaml backend/.gitignore backend/src/Entity/RefreshToken.php backend/migrations/ .env.example
git commit -m "feat(a1.4): install JWT bundles, generate keypair, add refresh_tokens table"
```

Do **not** add `backend/config/jwt/` — it's gitignored by design (Step 4).

---

## Task 2: `User` implements Symfony's security interfaces

**Files:**
- Modify: `backend/src/Entity/User.php`
- Test: `backend/tests/Entity/UserTest.php`

**Interfaces:**
- Consumes: `App\Entity\User` (A1.3, Task 5), `App\Entity\Enum\UserRole` (A1.3).
- Produces: `User implements UserInterface, PasswordAuthenticatedUserInterface` with `getRoles(): array`, `getPassword(): string`, `getUserIdentifier(): string`, `eraseCredentials(): void`. Consumed by Symfony Security in Task 3.

- [ ] **Step 1: Write the failing tests (appended to the existing `UserTest.php`)**

Add these methods inside the existing `final class UserTest extends TestCase` in `backend/tests/Entity/UserTest.php`:

```php
    public function testGetRolesMapsAdminRole(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::Admin);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testGetRolesMapsInternalAnalystRole(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);

        self::assertSame(['ROLE_INTERNAL_ANALYST', 'ROLE_USER'], $user->getRoles());
    }

    public function testGetRolesMapsExternalPartnerRole(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::ExternalPartner);

        self::assertSame(['ROLE_EXTERNAL_PARTNER', 'ROLE_USER'], $user->getRoles());
    }

    public function testGetPasswordReturnsPasswordHash(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::Admin);

        self::assertSame('hashed-password', $user->getPassword());
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::Admin);

        self::assertSame('amina@example.com', $user->getUserIdentifier());
    }

    public function testEraseCredentialsIsANoOp(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::Admin);

        $user->eraseCredentials();

        self::assertSame('hashed-password', $user->getPassword());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/UserTest.php`
Expected: FAIL — `Call to undefined method App\Entity\User::getRoles()` (and similarly for the other new methods).

- [ ] **Step 3: Implement the interfaces**

In `backend/src/Entity/User.php`, change the class declaration and imports, and add the four methods:

```php
use App\Entity\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Table(name: 'users')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
```

Add these methods (alongside the existing ones, e.g. after `getCreatedAt()`):

```php
    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roleName = match ($this->role) {
            UserRole::Admin => 'ROLE_ADMIN',
            UserRole::InternalAnalyst => 'ROLE_INTERNAL_ANALYST',
            UserRole::ExternalPartner => 'ROLE_EXTERNAL_PARTNER',
        };

        return [$roleName, 'ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        // No plaintext credential is ever held on this entity — nothing to erase.
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/UserTest.php`
Expected: PASS (8 tests: the 2 from A1.3 plus the 6 added here).

- [ ] **Step 5: Verify the mapping metadata is still valid**

Run: `docker compose exec backend php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.` (implementing interfaces doesn't add Doctrine-mapped fields, so this should be unaffected).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Entity/User.php backend/tests/Entity/UserTest.php
git commit -m "feat(a1.4): implement Symfony security interfaces on User"
```

---

## Task 3: Wire up `security.yaml` (firewalls, hasher, throttling, access_control)

**Files:**
- Modify: `backend/config/packages/security.yaml` (created by Flex when `symfony/security-bundle` installs as a dependency of the JWT bundles — if it doesn't exist yet after Task 1, create it)
- Modify: `backend/composer.json` (ensure `symfony/security-bundle` and `symfony/rate-limiter` are present)

**Interfaces:**
- Consumes: `App\Entity\User` (Task 2).
- Produces: a working security firewall configuration. Consumed by every controller task that follows (Tasks 4–7 rely on `/api/auth/login`, `/api/auth/refresh`, and `IS_AUTHENTICATED_FULLY` being enforced correctly).

- [ ] **Step 1: Ensure the rate limiter component is present**

Run: `docker compose exec backend composer require symfony/rate-limiter`
Expected: installs cleanly (it's a transitive need for `login_throttling`; Symfony Flex may have already required it alongside `security-bundle` — if `composer require` reports it's already installed, that's fine, move on).

- [ ] **Step 2: Write `security.yaml`**

Replace the full contents of `backend/config/packages/security.yaml` with:

```yaml
security:
    password_hashers:
        App\Entity\User:
            algorithm: auto

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email

    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false

        login:
            pattern: ^/api/auth/login
            stateless: true
            json_login:
                check_path: /api/auth/login
                username_path: email
                password_path: password
                success_handler: lexik_jwt_authentication.handler.authentication_success
                failure_handler: lexik_jwt_authentication.handler.authentication_failure
            login_throttling:
                max_attempts: 5
                interval: '15 minutes'

        api:
            pattern: ^/api
            stateless: true
            jwt: ~
            provider: app_user_provider

    access_control:
        - { path: ^/api/auth/login, roles: PUBLIC_ACCESS }
        - { path: ^/api/auth/refresh, roles: PUBLIC_ACCESS }
        - { path: ^/api/doc, roles: PUBLIC_ACCESS }
        - { path: ^/api, roles: IS_AUTHENTICATED_FULLY }

when@test:
    security:
        password_hashers:
            App\Entity\User:
                algorithm: auto
                cost: 4
```

The `when@test` override lowers the bcrypt/argon2 cost factor for the test suite only, so functional tests that hash passwords don't slow down — a standard Symfony testing convention, not a security-relevant change (production config is untouched).

- [ ] **Step 3: Verify the container compiles**

Run: `docker compose exec backend php bin/console cache:clear`
Expected: no errors. If `lexik_jwt_authentication.handler.authentication_success` or `.failure_handler` service IDs don't exist, this step fails loudly here — that's the signal Task 1's bundle installation needs revisiting before continuing.

- [ ] **Step 4: Verify existing tests still pass**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit`
Expected: all pre-existing tests (30 from A1.3 + 6 new from Task 2 = 36) still pass — `HealthController` and `doc` routes must remain reachable now that `access_control` is in effect. If `HealthControllerTest` or `ApiDocControllerTest` start failing with 401, add their routes to `access_control` as `PUBLIC_ACCESS` (check `backend/src/Controller/HealthController.php` and the Nelmio doc route for their exact path prefixes first, and only widen `access_control` for the actual matched path — don't blanket-disable `^/api`).

- [ ] **Step 5: Commit**

```bash
git add backend/config/packages/security.yaml backend/composer.json backend/composer.lock
git commit -m "feat(a1.4): configure security firewalls, password hasher, login throttling"
```

---

## Task 4: Login endpoint — functional tests

**Files:**
- Test: `backend/tests/Controller/AuthenticationControllerTest.php`

**Interfaces:**
- Consumes: the `login` firewall (Task 3), `App\Entity\User` (Task 2), `App\Entity\Enum\UserRole` (A1.3).
- Produces: a reusable `createTestUser()` helper in this test class, reused by Tasks 5–8 as they append more test methods to the same file.

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthenticationControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    private function createTestUser(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $email = 'amina@example.com',
        string $plainPassword = 'correct-horse-battery-staple',
        UserRole $role = UserRole::InternalAnalyst,
    ): User {
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('Amina Diallo', $email, 'placeholder', $role);
        $user->setPasswordHash($hasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    public function testLoginWithValidCredentialsReturnsTokenPair(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'amina@example.com', 'correct-horse-battery-staple');

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amina@example.com', 'password' => 'correct-horse-battery-staple']),
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('refresh_token', $data);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'amina2@example.com', 'correct-horse-battery-staple');

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amina2@example.com', 'password' => 'wrong-password']),
        );

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testLoginWithUnknownEmailFails(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'nobody@example.com', 'password' => 'whatever']),
        );

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
```

- [ ] **Step 2: Run to verify it fails first (there is no user yet, no route yet)**

Run: `docker compose exec -e APP_ENV=test backend php bin/console doctrine:database:create --if-not-exists && docker compose exec -e APP_ENV=test backend php bin/console doctrine:migrations:migrate --no-interaction`

Then run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Controller/AuthenticationControllerTest.php`
Expected: at this point Task 1–3 are already done, so `/api/auth/login` should already be wired (json_login + Lexik/Gesdinet handlers configured in Task 3). If these tests already pass here, that confirms Tasks 1–3 were done correctly — this step's "expected failure" is really a regression check on the prior tasks, not a new failing-first step. If any test fails, fix the firewall/handler config from Task 3 before moving on — do not add application code to work around a broken security config.

- [ ] **Step 3: Run to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Controller/AuthenticationControllerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Controller/AuthenticationControllerTest.php
git commit -m "test(a1.4): add functional tests for POST /api/auth/login"
```

---

## Task 5: Refresh endpoint — functional tests

**Files:**
- Test: `backend/tests/Controller/AuthenticationControllerTest.php` (append)

**Interfaces:**
- Consumes: `gesdinet_jwt_refresh_token` bundle's built-in refresh controller (Task 1), `createTestUser()` helper (Task 4).

- [ ] **Step 1: Confirm the refresh route path**

Run: `docker compose exec backend php bin/console debug:router | grep refresh`
Expected: a route matching `/api/auth/refresh` (or similar — Gesdinet's default path is `/token/refresh`; since `security.yaml`'s `access_control` in Task 3 already assumes `^/api/auth/refresh`, if the actual route differs, add `gesdinet_jwt_refresh_token: path: /api/auth/refresh` under the bundle's config in `backend/config/packages/gesdinet_jwt_refresh_token.yaml` from Task 1 and re-run this check).

- [ ] **Step 2: Append the failing tests**

Add inside `AuthenticationControllerTest`:

```php
    public function testRefreshWithValidTokenReturnsNewPair(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'amina3@example.com', 'correct-horse-battery-staple');

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amina3@example.com', 'password' => 'correct-horse-battery-staple']),
        );
        $loginData = json_decode($client->getResponse()->getContent(), true);

        $client->request(
            'POST',
            '/api/auth/refresh',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $loginData['refresh_token']]),
        );

        self::assertResponseIsSuccessful();
        $refreshData = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $refreshData);
        self::assertArrayHasKey('refresh_token', $refreshData);
        self::assertNotSame($loginData['token'], $refreshData['token']);
        self::assertNotSame($loginData['refresh_token'], $refreshData['refresh_token']);
    }

    public function testReusingARotatedRefreshTokenFails(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'amina4@example.com', 'correct-horse-battery-staple');

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amina4@example.com', 'password' => 'correct-horse-battery-staple']),
        );
        $loginData = json_decode($client->getResponse()->getContent(), true);

        // First refresh: rotates the token, old one becomes invalid (single_use: true).
        $client->request(
            'POST',
            '/api/auth/refresh',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $loginData['refresh_token']]),
        );
        self::assertResponseIsSuccessful();

        // Second refresh with the SAME (now-rotated-away) token must fail.
        $client->request(
            'POST',
            '/api/auth/refresh',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $loginData['refresh_token']]),
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
```

- [ ] **Step 3: Run to verify both pass**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Controller/AuthenticationControllerTest.php`
Expected: PASS (5 tests total). If `testReusingARotatedRefreshTokenFails` fails because the old token still works, confirm `single_use: true` is actually set in `backend/config/packages/gesdinet_jwt_refresh_token.yaml` (Task 1) and re-run.

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Controller/AuthenticationControllerTest.php
git commit -m "test(a1.4): add functional tests for POST /api/auth/refresh"
```

---

## Task 6: `GET /api/auth/me` endpoint

**Files:**
- Create: `backend/src/Controller/AuthenticationController.php`
- Test: `backend/tests/Controller/AuthenticationControllerTest.php` (append)

**Interfaces:**
- Consumes: `App\Entity\User` (Task 2), the `api` firewall (Task 3).
- Produces: `GET /api/auth/me`, consumed by nothing else in this plan (it's the acceptance-criteria endpoint per spec decision 10) but establishes `AuthenticationController` as the home for `logout` in Task 7.

- [ ] **Step 1: Append the failing tests**

```php
    public function testMeWithoutTokenFails(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/auth/me');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testMeWithValidTokenReturnsUserIdentity(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'amina5@example.com', 'correct-horse-battery-staple', UserRole::Admin);

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amina5@example.com', 'password' => 'correct-horse-battery-staple']),
        );
        $loginData = json_decode($client->getResponse()->getContent(), true);

        $client->request(
            'GET',
            '/api/auth/me',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$loginData['token']],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('amina5@example.com', $data['email']);
        self::assertSame('admin', $data['role']);
    }
```

- [ ] **Step 2: Run to verify `testMeWithValidTokenReturnsUserIdentity` fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Controller/AuthenticationControllerTest.php`
Expected: `testMeWithoutTokenFails` already passes (no route = 404, which the test doesn't distinguish from 401 — but Symfony's `access_control` returning 401 for an unmatched-but-firewall-covered path is what actually happens here since `^/api` requires `IS_AUTHENTICATED_FULLY` even for a 404 route; if you see 404 instead of 401, that's fine for this specific test since it only asserts non-success — but `testMeWithValidTokenReturnsUserIdentity` must fail with a routing error, since no controller answers `GET /api/auth/me` yet).

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AuthenticationController extends AbstractController
{
    #[Route('/api/auth/me', name: 'auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'email' => $user->getUserIdentifier(),
            'role' => strtolower(str_replace('ROLE_', '', $user->getRoles()[0])),
        ]);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Controller/AuthenticationControllerTest.php`
Expected: PASS (7 tests total).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Controller/AuthenticationController.php backend/tests/Controller/AuthenticationControllerTest.php
git commit -m "feat(a1.4): add GET /api/auth/me endpoint"
```

---

## Task 7: `POST /api/auth/logout` endpoint

**Files:**
- Modify: `backend/src/Controller/AuthenticationController.php`
- Modify: `backend/config/packages/security.yaml` (add `/api/auth/logout` isn't needed — it already falls under the catch-all `^/api` → `IS_AUTHENTICATED_FULLY` rule from Task 3)
- Test: `backend/tests/Controller/AuthenticationControllerTest.php` (append)

**Interfaces:**
- Consumes: `Gesdinet\JWTRefreshTokenBundle\Doctrine\RefreshTokenManager` (bundle service, Task 1), `App\Entity\User` (Task 2).
- Produces: `POST /api/auth/logout`, revokes the authenticated user's refresh tokens.

- [ ] **Step 1: Append the failing tests**

```php
    public function testLogoutRevokesRefreshToken(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'amina6@example.com', 'correct-horse-battery-staple');

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amina6@example.com', 'password' => 'correct-horse-battery-staple']),
        );
        $loginData = json_decode($client->getResponse()->getContent(), true);

        $client->request(
            'POST',
            '/api/auth/logout',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$loginData['token']],
        );
        self::assertSame(204, $client->getResponse()->getStatusCode());

        // The refresh token issued at login must no longer work after logout.
        $client->request(
            'POST',
            '/api/auth/refresh',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $loginData['refresh_token']]),
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testLogoutWithoutTokenFails(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/auth/logout');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
```

- [ ] **Step 2: Run to verify `testLogoutRevokesRefreshToken` fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Controller/AuthenticationControllerTest.php`
Expected: FAIL — no route answers `POST /api/auth/logout` yet.

- [ ] **Step 3: Add the logout action**

Add to `backend/src/Controller/AuthenticationController.php` (new constructor + method; keep the existing `me()` method):

```php
use Gesdinet\JWTRefreshTokenBundle\Doctrine\RefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
```

```php
final class AuthenticationController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
    ) {
    }

    #[Route('/api/auth/me', name: 'auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'email' => $user->getUserIdentifier(),
            'role' => strtolower(str_replace('ROLE_', '', $user->getRoles()[0])),
        ]);
    }

    #[Route('/api/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $refreshTokens = $this->refreshTokenManager->getRepository()->findBy(['username' => $user->getUserIdentifier()]);
        foreach ($refreshTokens as $refreshToken) {
            $this->refreshTokenManager->delete($refreshToken);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
```

`RefreshTokenManagerInterface::getRepository()` returns the Doctrine repository for `App\Entity\RefreshToken` (Task 1, Step 5); `findBy(['username' => ...])` matches the column Gesdinet stores the JWT username identifier in. Before writing this method, run `docker compose exec backend php bin/console debug:container gesdinet_jwt_refresh_token.refresh_token_manager` to confirm the exact interface/class installed, and open `vendor/gesdinet/jwt-refresh-token-bundle/Doctrine/RefreshTokenManagerInterface.php` to confirm `getRepository()` and `delete()` are present with these signatures — adjust only if the installed version genuinely differs, keeping the same intent: find and delete every refresh token owned by the current user.

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Controller/AuthenticationControllerTest.php`
Expected: PASS (9 tests total).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Controller/AuthenticationController.php backend/tests/Controller/AuthenticationControllerTest.php
git commit -m "feat(a1.4): add POST /api/auth/logout endpoint"
```

---

## Task 8: Anti-brute-force — functional test

**Files:**
- Test: `backend/tests/Controller/AuthenticationControllerTest.php` (append)

**Interfaces:**
- Consumes: `login_throttling` config (Task 3).

- [ ] **Step 1: Append the failing test**

```php
    public function testSixthFailedLoginAttemptIsThrottled(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'amina7@example.com', 'correct-horse-battery-staple');

        for ($i = 0; $i < 5; ++$i) {
            $client->request(
                'POST',
                '/api/auth/login',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['email' => 'amina7@example.com', 'password' => 'wrong-password']),
            );
            self::assertSame(401, $client->getResponse()->getStatusCode(), "Attempt {$i} should be a plain auth failure, not a lockout yet.");
        }

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amina7@example.com', 'password' => 'wrong-password']),
        );
        self::assertSame(429, $client->getResponse()->getStatusCode());
    }
```

- [ ] **Step 2: Run to verify it fails or passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Controller/AuthenticationControllerTest.php --filter testSixthFailedLoginAttemptIsThrottled`
Expected: this exercises config already written in Task 3 (`login_throttling: max_attempts: 5`), so it should already pass if that config is correct — treat a failure here as a signal to fix Task 3's `security.yaml`, not as something to newly implement. If the 6th attempt returns 401 instead of 429, confirm `symfony/rate-limiter` is installed (Task 3, Step 1) and that `login_throttling` is nested correctly under the `login` firewall (not `api`).

- [ ] **Step 3: Run the full test file to confirm no regression**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Controller/AuthenticationControllerTest.php`
Expected: PASS (10 tests total).

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Controller/AuthenticationControllerTest.php
git commit -m "test(a1.4): add functional test for login throttling"
```

---

## Task 9: Update README

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: nothing. Produces: nothing consumed by later tasks — final task of A1.4.

- [ ] **Step 1: Update "Prochaine étape"**

Replace:

```markdown
## Prochaine étape

Les fondations Docker et Symfony (Points 1 et 2) sont posées. TimescaleDB est déployé et le schéma « pipeline-ready » (Point 3 — A1.3) est en place : voir la section « Schéma de données » ci-dessus. La suite du plan (Point 4 — authentification JWT, A1.4) sera traitée séparément.
```

with:

```markdown
## Prochaine étape

Les fondations Docker et Symfony (Points 1 et 2) sont posées, TimescaleDB et le schéma « pipeline-ready » (Point 3 — A1.3) sont en place, et l'authentification JWT (Point 4 — A1.4) est opérationnelle : voir la section « Authentification » ci-dessous. La suite du plan (Phase A2 — fonctionnalités et intégration frontend) sera traitée séparément.
```

- [ ] **Step 2: Add an "Authentification" section**

Insert this new section right before "## Prochaine étape":

```markdown
## Authentification

Authentification par JWT (access token courte durée + refresh token), conforme à la section 5.2.a du cahier des charges. Détails de conception : voir
[`docs/superpowers/specs/2026-08-24-a14-jwt-authentication-design.md`](docs/superpowers/specs/2026-08-24-a14-jwt-authentication-design.md).

### Générer la paire de clés JWT (une fois, en local)

```bash
docker compose exec backend php bin/console lexik:jwt:generate-keypair --overwrite
```

Nécessite `JWT_PASSPHRASE` déjà défini dans `.env` (voir `.env.example`). Les clés (`backend/config/jwt/*.pem`) ne sont jamais versionnées.

### Endpoints

| Endpoint | Auth requise | Description |
|---|---|---|
| `POST /api/auth/login` | Non | `{email, password}` → `{token, refresh_token}` |
| `POST /api/auth/refresh` | Non | `{refresh_token}` → nouvelle paire (rotation à usage unique) |
| `GET /api/auth/me` | Oui (Bearer token) | `{email, role}` de l'utilisateur authentifié |
| `POST /api/auth/logout` | Oui (Bearer token) | Révoque le(s) refresh token(s) de l'utilisateur — 204 |

### Durées de vie

- Access token : 15 minutes
- Refresh token : 30 jours, à usage unique (rotation à chaque `/api/auth/refresh`)

### Protection anti-brute-force

5 tentatives de connexion échouées par identifiant sur une fenêtre de 15 minutes déclenchent un blocage temporaire (`429 Too Many Requests`).

### Rôles

`User.role` (`Admin` / `InternalAnalyst` / `ExternalPartner`) est mappé vers `ROLE_ADMIN` / `ROLE_INTERNAL_ANALYST` / `ROLE_EXTERNAL_PARTNER` (+ `ROLE_USER` pour tout utilisateur authentifié). Le "Visiteur" du cahier des charges correspond à l'absence de compte, donc à l'absence de jeton — pas à un rôle stocké en base.
```

- [ ] **Step 3: Verify the README renders sensibly**

Run: `docker compose exec backend cat README.md | head -250` (or open locally) and confirm no broken Markdown (matching fences, no stray headers).

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs(a1.4): document JWT authentication endpoints and setup"
```

---

## Final check before considering A1.4 done

- [ ] Run `docker compose exec -e APP_ENV=test backend php bin/phpunit` twice in a row — full suite green both times (no leftover data between runs, same discipline as A1.3's integration tests).
- [ ] Run `docker compose exec backend php bin/console doctrine:schema:validate` — both `[OK]` lines.
- [ ] Cross-check against the plan spreadsheet: A1.4's "Livrable attendu" was *"Endpoints d'authentification opérationnels, jetons JWT + refresh token"* — confirm all four endpoints (`login`, `refresh`, `me`, `logout`) work per the functional tests, and that the four roles from A1.3 are enforced (`getRoles()` mapping verified in Task 2).
- [ ] Both A1.3 and A1.4 are now complete. Per Serge's stated strategy, push **both** to `origin/developp` together now: `git push origin developp`.
