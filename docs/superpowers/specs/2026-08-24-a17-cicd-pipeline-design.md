# A1.7 — CI/CD Pipeline (GitLab CI)

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-24
Plan reference: A1.7 (Phase A1 — Fondations), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 5.8 (livrables), section 8 (maintenabilité)

## Goal

Set up a GitLab CI/CD pipeline that runs the automated test suite on every push (any
branch) and, on `developp` only, builds and publishes the backend Docker image to the
project's GitLab Container Registry. Matches the plan's A1.7 livrable: *"Pipeline CI/CD
opérationnel sur le dépôt Git."*

## Non-goals (explicitly out of scope for A1.7)

- **Deploying to a live/production server.** No such environment exists yet — that's A3.8
  ("Déployer l'environnement de production") in the plan. This pipeline's "build" stage
  publishes a Docker image to the GitLab Container Registry; nothing pulls or runs that
  image anywhere automatically. Calling this a "deployment" would overstate what it does —
  it is documented everywhere as **image build & publish**, not deployment.
- Provisioning a dedicated GitLab Runner. Confirmed via **Settings → CI/CD → Runners →
  Instance (135)** that gitlab.com's shared runner pool is available to this project; no
  project-specific runner is needed.
- Linting/static analysis tools (PHPStan, PHP-CS-Fixer, etc.) — not requested by the cahier
  des charges or the plan for A1.7; would be scope creep here.
- Frontend CI — `frontend/` is still empty (Phase A2), nothing to build or test yet.

## Decisions

1. **Platform: native GitLab CI/CD** (`.gitlab-ci.yml`), not an external CI tool — the
   repository is hosted on gitlab.com, so this is the path of least friction and the shared
   runner pool is already available (verified above).
2. **Two stages, `test` then `build`.** `test` runs on every push to every branch (protects
   against regressions on feature work too, including from a future MR-based workflow).
   `build` runs only on `developp` and only after `test` passes (`needs: ["phpunit"]`) —
   publishing an image from unfinished work on another branch has no value.
3. **Test job runs in a generic `php:8.4-cli` image, not the project's own Docker image.**
   The job manually installs the same PHP extensions as `docker/backend/Dockerfile`
   (`pdo`, `pdo_pgsql`, `intl`, `opcache`, `zip`) and Composer, rather than building the full
   Docker image first and testing inside it. Chosen over the alternative (test inside a
   freshly built Docker image via Docker-in-Docker on every push) because it keeps every
   push fast and avoids Docker-in-Docker overhead on the common path — the tradeoff, accepted
   explicitly, is that the PHP extension list is now duplicated between `Dockerfile` and
   `.gitlab-ci.yml` and must be kept in sync by hand if either changes (documented in the
   README's "Points d'attention").
4. **No GitLab CI/CD variables to configure manually.** The registry credentials
   (`$CI_REGISTRY`, `$CI_REGISTRY_IMAGE`, `$CI_REGISTRY_USER`, `$CI_REGISTRY_PASSWORD`) and
   commit metadata (`$CI_COMMIT_SHORT_SHA`, `$CI_COMMIT_BRANCH`) are all provided
   automatically by GitLab for every pipeline run. The test job's `POSTGRES_*` credentials
   and `JWT_PASSPHRASE` are throwaway values hard-coded directly in `.gitlab-ci.yml`: the
   Postgres/TimescaleDB service database is destroyed at the end of every job, and the JWT
   keypair is regenerated fresh in every run and never reused — neither is real secret
   material, so committing them in plaintext in the pipeline config carries no risk
   (consistent with the `backend/.env` placeholder convention documented in A1.4's "Points
   d'attention" — this is the ephemeral-CI equivalent, not an exception to that rule).
   `JWT_SECRET_KEY`/`JWT_PUBLIC_KEY` need no CI override at all: `backend/.env` (committed)
   already points them at `config/jwt/{private,public}.pem`, which is exactly where the test
   job's `lexik:jwt:generate-keypair` step writes the freshly generated pair.
5. **Image tags: commit SHA + `developp`.** `$CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA`
   (immutable, one per build) and `$CI_REGISTRY_IMAGE/backend:developp` (moving tag, always
   the latest successful build from `developp`) are both pushed. This gives an audit trail
   (every commit's image is retrievable by SHA) and a convenient "latest known-good" tag for
   whenever A3.8 needs to pull one.
6. **Test database service: `timescale/timescaledb:latest-pg16`**, matching the `database`
   service in `docker-compose.yml` exactly — the same image is used everywhere data is
   stored, avoiding a TimescaleDB-vs-plain-Postgres behavioral gap between CI and local dev.
7. **Composer dependency caching**, keyed on `composer.lock`'s hash, to keep pipeline runs
   fast across commits that don't change dependencies. A minor efficiency addition, not a
   correctness requirement.

## Pipeline design

```yaml
stages:
  - test
  - build

phpunit:
  stage: test
  image: php:8.4-cli
  services:
    - name: timescale/timescaledb:latest-pg16
      alias: database
  variables:
    POSTGRES_DB: nev_climate_data_ci
    POSTGRES_USER: nev_ci
    POSTGRES_PASSWORD: nev_ci_password
    DATABASE_URL: "postgresql://nev_ci:nev_ci_password@database:5432/nev_climate_data_ci?serverVersion=16&charset=utf8"
    APP_ENV: test
    JWT_PASSPHRASE: ci_ephemeral_passphrase
  cache:
    key:
      files:
        - backend/composer.lock
    paths:
      - backend/vendor/
  before_script:
    - apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libzip-dev zip
    - docker-php-ext-install pdo pdo_pgsql intl opcache zip
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  script:
    - cd backend
    - composer install --no-interaction --no-progress --prefer-dist
    - mkdir -p config/jwt
    - php bin/console lexik:jwt:generate-keypair --overwrite
    - php bin/console doctrine:database:create --if-not-exists
    - php bin/console doctrine:migrations:migrate --no-interaction
    - php bin/phpunit

build_and_push_image:
  stage: build
  image: docker:27-cli
  services:
    - docker:27-dind
  needs: ["phpunit"]
  rules:
    - if: '$CI_COMMIT_BRANCH == "developp"'
  script:
    - docker login -u "$CI_REGISTRY_USER" -p "$CI_REGISTRY_PASSWORD" "$CI_REGISTRY"
    - docker build -t "$CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA" -t "$CI_REGISTRY_IMAGE/backend:developp" -f docker/backend/Dockerfile .
    - docker push "$CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA"
    - docker push "$CI_REGISTRY_IMAGE/backend:developp"
```

This is the design's reference shape; the implementation plan may adjust exact syntax
(e.g. `rules` vs `only`) to whatever the installed GitLab version expects, verified by
actually running the pipeline — but the stages, jobs, triggers, and variables above are the
contract the implementation must satisfy.

## Verification (how A1.7 is confirmed done)

1. Push a commit to a throwaway branch → `phpunit` job runs and passes; `build_and_push_image`
   does not run (branch ≠ `developp`).
2. Push a commit to `developp` → both jobs run; the image appears in **Packages and
   registries → Container Registry** with both the commit-SHA tag and the `developp` tag.
3. Deliberately break one test on a throwaway branch → `phpunit` fails red; confirm this
   would have blocked `build_and_push_image` had it been on `developp` (via `needs`).
   Revert the deliberate breakage before merging anything real.

## Documentation

`README.md` gains a "CI/CD" section: the two stages explained, how to read pipeline status
on GitLab, and an explicit, unambiguous statement that this pipeline does **not** deploy to
any server — it publishes a Docker image to the Container Registry, and real deployment is
A3.8. The "Points d'attention" section gains an entry about the manually-duplicated PHP
extension list (decision 3). The "État d'avancement" table is updated to mark A1.7 done.
