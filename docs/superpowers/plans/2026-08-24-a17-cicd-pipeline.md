# A1.7 CI/CD Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a working GitLab CI/CD pipeline (`.gitlab-ci.yml`) that runs the test suite on every push and, on `developp` only, builds and publishes the backend Docker image to the GitLab Container Registry.

**Architecture:** Two GitLab CI stages, `test` (runs everywhere) and `build` (runs only on `developp`, gated by `needs` on the test job). Built and verified iteratively on a throwaway branch before ever touching `developp`, so nothing broken is ever pushed to the shared branch.

**Tech Stack:** GitLab CI/CD (native, gitlab.com shared runners), `php:8.4-cli` for the test job, `docker:27-cli` + `docker:27-dind` for the build job, `timescale/timescaledb:latest-pg16` as the CI database service.

## Global Constraints

- No project deployment happens in A1.7 — the `build` job publishes a Docker image to the Container Registry, nothing pulls or runs it anywhere. Document this explicitly everywhere (spec decision, Non-goals).
- No GitLab CI/CD variables are configured manually in project Settings — everything needed is either a GitLab-provided predefined variable or a throwaway value hard-coded in `.gitlab-ci.yml` (spec decision 4).
- `test` job's PHP extensions (`pdo`, `pdo_pgsql`, `intl`, `opcache`, `zip`) must match `docker/backend/Dockerfile`'s list exactly — this duplication is accepted and must be flagged in the README (spec decision 3).
- CI database service: `timescale/timescaledb:latest-pg16`, matching `docker-compose.yml`'s `database` service (spec decision 6).
- Image tags on `developp`: both `$CI_COMMIT_SHORT_SHA` and `developp` (spec decision 5).
- Nothing is pushed to `developp` until the full pipeline (test + build, including the failure-blocks-build behavior) has been verified working on a throwaway branch first.

---

## Task 1: Test-only pipeline, verified on a throwaway branch

**Files:**
- Create: `.gitlab-ci.yml` (repo root)

**Interfaces:**
- Produces: a `test` stage with a `phpunit` job. Consumed by Task 2 (which adds a `build` stage depending on this job via `needs: ["phpunit"]`).

- [ ] **Step 1: Create the throwaway verification branch**

Run:
```bash
git checkout -b ci/a17-pipeline-verification
```

- [ ] **Step 2: Write `.gitlab-ci.yml` with only the test stage**

```yaml
stages:
  - test

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
```

- [ ] **Step 3: Commit and push the throwaway branch**

```bash
git add .gitlab-ci.yml
git commit -m "ci(a17): add test-only pipeline for verification"
git push -u origin ci/a17-pipeline-verification
```

- [ ] **Step 4: Verify the pipeline runs and the test job passes**

Check: `https://gitlab.com/nev-consulting-group/nev-climate-data/-/pipelines?ref=ci/a17-pipeline-verification`
Expected: a pipeline appears with a single `phpunit` job in the `test` stage, ending green (passed). If it fails, open the job log from that page, fix the root cause in `.gitlab-ci.yml` (common culprits: an `apt-get`/extension name typo, a wrong `DATABASE_URL`, a missing `mkdir -p config/jwt` before key generation), commit, and push again — repeat until green.

---

## Task 2: Add the build/push job, verified safely on the same throwaway branch

**Files:**
- Modify: `.gitlab-ci.yml`

**Interfaces:**
- Consumes: the `phpunit` job from Task 1 (`needs: ["phpunit"]`).
- Produces: a `build` stage with a `build_and_push_image` job. In this task, its `rules` condition temporarily targets the throwaway branch itself (not `developp` yet) so the full build+push flow can be verified without any risk to `developp` — Task 4 flips this to the real, permanent condition before the final push.

- [ ] **Step 1: Add the build stage and job**

Update `.gitlab-ci.yml`'s `stages:` list and append the new job:

```yaml
stages:
  - test
  - build
```

```yaml
build_and_push_image:
  stage: build
  image: docker:27-cli
  services:
    - docker:27-dind
  needs: ["phpunit"]
  rules:
    - if: '$CI_COMMIT_BRANCH == "ci/a17-pipeline-verification"'
  script:
    - docker login -u "$CI_REGISTRY_USER" -p "$CI_REGISTRY_PASSWORD" "$CI_REGISTRY"
    - docker build -t "$CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA" -f docker/backend/Dockerfile .
    - docker push "$CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA"
```

The `rules` condition here is **intentionally temporary** — it checks for the throwaway branch name specifically, so this task's verification exercises the real build+push logic for real, without going anywhere near `developp`. Only the commit-SHA tag is pushed at this stage: the throwaway branch's own name, `ci/a17-pipeline-verification`, contains a `/`, which Docker does not accept inside a tag string — using `$CI_COMMIT_BRANCH` as a tag here would fail. The second, fixed `developp` tag (spec decision 5) is added in Task 4, once the branch check points at `developp` (a name with no `/`, safe as a tag).

- [ ] **Step 2: Commit and push**

```bash
git add .gitlab-ci.yml
git commit -m "ci(a17): add build/push job, temporarily scoped to this branch for verification"
git push
```

- [ ] **Step 3: Verify both jobs run and the image is published**

Check: `https://gitlab.com/nev-consulting-group/nev-climate-data/-/pipelines?ref=ci/a17-pipeline-verification`
Expected: a new pipeline with two jobs, `phpunit` (test stage) then `build_and_push_image` (build stage), both green, `build_and_push_image` starting only after `phpunit` finishes.

Then check: `https://gitlab.com/nev-consulting-group/nev-climate-data/-/packages` (Container Registry section)
Expected: an image `backend` with a tag matching the short commit SHA from the pipeline.

---

## Task 3: Verify a failing test blocks the build job

**Files:**
- Modify: `backend/tests/Entity/CountryTest.php` (temporarily, reverted at the end of this task)

**Interfaces:**
- Consumes: the `needs: ["phpunit"]` relationship from Task 2.
- Produces: nothing new — this task only verifies existing behavior. No later task depends on anything from this one.

- [ ] **Step 1: Temporarily break a test**

In `backend/tests/Entity/CountryTest.php`, change one assertion to something guaranteed false, e.g.:

```php
    public function testConstructorSetsFields(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa');

        self::assertNull($country->getId());
        self::assertSame('THIS ASSERTION IS DELIBERATELY WRONG', $country->getName());
```

- [ ] **Step 2: Commit and push the deliberate breakage**

```bash
git add backend/tests/Entity/CountryTest.php
git commit -m "ci(a17): deliberately break a test to verify build is blocked on failure"
git push
```

- [ ] **Step 3: Verify the test job fails and the build job does not run**

Check: `https://gitlab.com/nev-consulting-group/nev-climate-data/-/pipelines?ref=ci/a17-pipeline-verification`
Expected: `phpunit` shows red (failed). `build_and_push_image` shows as **skipped** (not attempted) — this is GitLab's standard behavior for a job whose `needs` dependency failed. This confirms the exact mechanism that will protect `developp`: once the `rules` condition is switched to `developp` in Task 4, a failing test there will skip the image publish the same way.

- [ ] **Step 4: Revert the deliberate breakage**

```bash
git revert HEAD --no-edit
git push
```

- [ ] **Step 5: Verify the pipeline is green again**

Check: `https://gitlab.com/nev-consulting-group/nev-climate-data/-/pipelines?ref=ci/a17-pipeline-verification`
Expected: newest pipeline has both `phpunit` and `build_and_push_image` green again, confirming the revert didn't leave anything broken.

---

## Task 4: Finalize for `developp`, document, clean up

**Files:**
- Modify: `.gitlab-ci.yml` (flip the `rules` condition to `developp`, and the second image tag)
- Modify: `README.md` (new "CI/CD" section, "Points d'attention" addition, "État d'avancement" update)

**Interfaces:**
- Consumes: the fully-verified `.gitlab-ci.yml` from Tasks 1–3.
- Produces: nothing consumed elsewhere — this is the final task of A1.7.

- [ ] **Step 1: Point the build job at `developp` and add the second image tag**

In `.gitlab-ci.yml`, change the `build_and_push_image` job's `rules` and `script` from:

```yaml
  rules:
    - if: '$CI_COMMIT_BRANCH == "ci/a17-pipeline-verification"'
  script:
    - docker login -u "$CI_REGISTRY_USER" -p "$CI_REGISTRY_PASSWORD" "$CI_REGISTRY"
    - docker build -t "$CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA" -f docker/backend/Dockerfile .
    - docker push "$CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA"
```

to:

```yaml
  rules:
    - if: '$CI_COMMIT_BRANCH == "developp"'
  script:
    - docker login -u "$CI_REGISTRY_USER" -p "$CI_REGISTRY_PASSWORD" "$CI_REGISTRY"
    - docker build -t "$CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA" -t "$CI_REGISTRY_IMAGE/backend:developp" -f docker/backend/Dockerfile .
    - docker push "$CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA"
    - docker push "$CI_REGISTRY_IMAGE/backend:developp"
```

The second tag is the fixed string `developp`, not `$CI_COMMIT_BRANCH` — on `developp` itself the two are identical in value, but hard-coding it removes any dependency on the branch name never containing a `/` in the future (spec decision 5).

- [ ] **Step 2: Add the "CI/CD" section to `README.md`**

Insert this new section right before "## Points d'attention":

```markdown
## CI/CD

Pipeline GitLab CI (`.gitlab-ci.yml`), deux étapes :

| Étape | Job | Déclenchement | Rôle |
|---|---|---|---|
| `test` | `phpunit` | Tout push, toute branche | Installe PHP 8.4 + extensions, lance la suite PHPUnit contre un service TimescaleDB éphémère |
| `build` | `build_and_push_image` | Uniquement `developp`, et seulement si `phpunit` a réussi | Construit l'image Docker backend, la publie sur le Container Registry GitLab (tags : SHA du commit + `developp`) |

**Important — ceci n'est pas un déploiement.** L'image est publiée dans le Container Registry du projet ; rien ne la récupère ni ne la fait tourner automatiquement quelque part. Le déploiement d'un environnement de production réel est la tâche A3.8, plus tard dans le plan.

### Suivre l'état d'un pipeline

`https://gitlab.com/nev-consulting-group/nev-climate-data/-/pipelines`

### Voir les images publiées

`https://gitlab.com/nev-consulting-group/nev-climate-data/-/packages` (section Container Registry)

### Runners

Aucun runner dédié n'est configuré pour ce projet — le pool de runners partagés gitlab.com (confirmé disponible : **Settings → CI/CD → Runners → Instance**) est utilisé.

Détails de conception complets : voir [`docs/superpowers/specs/2026-08-24-a17-cicd-pipeline-design.md`](docs/superpowers/specs/2026-08-24-a17-cicd-pipeline-design.md).
```

- [ ] **Step 3: Add an entry to "Points d'attention"**

Append as a new numbered point at the end of that section's list (renumber if needed to keep it sequential):

```markdown
9. **La liste d'extensions PHP du job `phpunit` (`.gitlab-ci.yml`) est dupliquée depuis `docker/backend/Dockerfile`, pas partagée.** Le job de test tourne dans une image PHP générique, pas dans l'image Docker du projet (choix documenté dans le spec A1.7, pour garder le pipeline rapide sur chaque push). Si une extension PHP est ajoutée/retirée du `Dockerfile`, il faut penser à répercuter le changement dans `.gitlab-ci.yml` — rien ne le fait automatiquement, et un oubli ne casse rien immédiatement (juste une divergence silencieuse entre l'environnement testé et l'environnement réel).
```

- [ ] **Step 4: Update "État d'avancement"**

Change the A1.7 row from:

```markdown
| A1.7 | Pipeline CI/CD | ⬜ Reste à faire |
```

to:

```markdown
| A1.7 | Pipeline CI/CD (build, tests, publication d'image) | ✅ Fait — publie l'image sur le Container Registry, ne déploie pas (voir section CI/CD) |
```

- [ ] **Step 5: Delete the throwaway verification branch**

```bash
git checkout developp
git branch -D ci/a17-pipeline-verification
git push origin --delete ci/a17-pipeline-verification
```

- [ ] **Step 6: Commit the finalized files directly on `developp`**

```bash
git add .gitlab-ci.yml README.md
git commit -m "feat(a1.7): add GitLab CI/CD pipeline (test + build/push image on developp)"
```

- [ ] **Step 7: Push to `developp` and verify the real pipeline**

Run: `git push origin developp`

Check: `https://gitlab.com/nev-consulting-group/nev-climate-data/-/pipelines?ref=developp`
Expected: `phpunit` and `build_and_push_image` both green.

Check: `https://gitlab.com/nev-consulting-group/nev-climate-data/-/packages`
Expected: `backend` image now has a tag matching this push's commit SHA, plus a `developp` tag.

---

## Final check before considering A1.7 done

- [ ] The throwaway branch `ci/a17-pipeline-verification` no longer exists locally or on `origin` (Task 4, Step 5).
- [ ] `git log --oneline` on `developp` shows exactly one new commit for this task: `feat(a1.7): add GitLab CI/CD pipeline...` (the throwaway branch's iteration commits never touched `developp`).
- [ ] The pipeline for the latest `developp` commit is green end-to-end (Task 4, Step 7).
- [ ] The `backend` image exists in the Container Registry with both a commit-SHA tag and a `developp` tag.
- [ ] Cross-check against the plan spreadsheet: A1.7's "Livrable attendu" was *"Pipeline CI/CD opérationnel sur le dépôt Git"* — confirmed by the green pipeline on `developp`.
