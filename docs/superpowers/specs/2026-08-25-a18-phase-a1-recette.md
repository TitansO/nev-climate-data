# A1.8 — Recette Phase A1 (Auth → API → Base de données)

Status: Exécutée
Author: Serge (with Claude)
Date: 2026-08-25 / 2026-08-26
Plan reference: A1.8 (Phase A1 — Fondations), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 10 (Gouvernance de projet — jalons de validation obligatoires)

## Goal

Produce a signed acceptance report validating that everything actually built in Phase A1
(A1.1–A1.7) works end-to-end, before Phase A2 begins. Matches the plan's A1.8 livrable:
*"Rapport de recette Phase A1 signé."*

## Scope (agreed with Serge before writing this checklist)

Bounded strictly to what A1.1–A1.7 delivered: infrastructure, API skeleton, pipeline-ready
schema, demo fixtures, JWT authentication, API key management, CI/CD pipeline. The broader
"Volet A" recette criteria in cahier des charges section 5.10 (export, filters, dashboards,
mobile UI, production HTTPS) are **explicitly out of scope** here — those features don't
exist yet (Phase A2/A3). This document is the Phase A1 gate only, matching the plan's own
per-phase recette structure (section 10: *"chaque phase (A1, A2, A3, puis B1, B2, B3) fait
l'objet d'une recette formelle avant passage à la phase suivante"*).

## Method

Each row below is executed directly (Docker/PHP/curl commands run in this session; GitLab
pipeline status confirmed by Serge, same constraint as A1.7 — no API token available).

Order matters: fixtures are loaded early (Auth/API-key checks reuse the demo users it
creates, rather than inventing throwaway data).

## Finding — blocking bug discovered and fixed during this recette

**`docker/backend/vhost.conf` did not forward the `Authorization` HTTP header to PHP.**
Apache strips this header from the PHP environment by default (a long-standing Apache
security default); without an explicit `CGIPassAuth On`, every JWT bearer-token and
`X-API-Key` request against the *real* running server failed with a false
`"JWT Token not found"` / `401`, even with a perfectly valid token.

**Why the automated test suite (65 tests) never caught this:** `AuthenticationControllerTest`
and `ApiKeyControllerTest` use Symfony's `WebTestCase`/`KernelBrowser`, which calls the
Symfony kernel directly, in-process — it never goes through Apache. The bug was invisible to
every automated test while being 100% blocking for real HTTP usage. This is precisely the
class of gap a recette against the actually-running system (not just the test suite) exists
to catch.

**Fix applied:** added `CGIPassAuth On` inside the `<Directory /var/www/html/public>` block
of `docker/backend/vhost.conf` (the directive is invalid directly inside `<VirtualHost>` —
first attempt failed Apache's config syntax check, corrected before the second rebuild).
Verified: full Section 5 and Section 6 checklists below were re-run after the fix, all pass.
The full PHPUnit suite (65/65) was re-run after the fix too, confirming no regression.

This fix is also logged as a new "Points d'attention" entry in `README.md` (#10), since it's
exactly the kind of gotcha that section exists to prevent from resurfacing.

## Checklist / Report

### 1. Infrastructure (A1.1)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Les deux conteneurs démarrent et sont sains | `docker compose ps` | `backend` Up, `database` Up (healthy) | ✅ |

### 2. API skeleton (A1.2)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Endpoint de santé répond | `curl http://localhost:8080/api/health` | `{"status":"ok","service":"NEV Climate Data API"}` | ✅ |
| Documentation OpenAPI accessible et valide | `curl http://localhost:8080/api/doc.json` | JSON OpenAPI 3.0.0 valide, titre/version corrects | ✅ |

### 3. Schéma de données (A1.3)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Les 3 migrations sont appliquées, aucune nouvelle | `doctrine:migrations:status` | Executed: 3, Available: 3, New: 0 | ✅ |
| Mapping + synchronisation base valides | `doctrine:schema:validate` | `[OK]` mapping + `[OK]` synchronisation | ✅ |

### 4. Données de démonstration (A1.6)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Les fixtures se chargent sans erreur | `doctrine:fixtures:load --no-interaction` | 8 fixtures chargées (purge + reload), aucune erreur | ✅ |
| Les volumes correspondent au README (54 pays, 5 secteurs, 1080 financements, 3 utilisateurs, 2 clés API, 4 sources, 6 rapports, 7 notifications) | requête SQL de comptage via `dbal:run-sql` | countries=54, sectors=5, funding=1080, users=3, api_keys=2, reports=6, notifications=7, sources=4 — correspondance exacte | ✅ |

### 5. Authentification (A1.4)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Login avec identifiants valides → token + refresh_token | `curl -X POST /api/auth/login` (admin de démo) | HTTP 200, token JWT + refresh_token présents, claims `ROLE_ADMIN`/`ROLE_USER` corrects | ✅ |
| Login avec mauvais mot de passe → 401 | idem, mauvais mot de passe | HTTP 401 | ✅ |
| `/api/auth/me` sans token → 401 | `curl /api/auth/me` sans en-tête | HTTP 401 | ✅ |
| `/api/auth/me` avec token → 200, rôle correct | `curl /api/auth/me` avec `Authorization: Bearer` | HTTP 200, `{"email":"admin@nev-climate-data.demo","role":"admin"}` — **échoué avant le correctif Apache (401 "JWT Token not found"), passe après** | ✅ (après correctif) |
| Refresh token → nouvelle paire | `curl -X POST /api/auth/refresh` | HTTP 200, nouvelle paire token/refresh_token | ✅ |
| Logout → refresh token révoqué | `curl -X POST /api/auth/logout` puis re-tentative de refresh | Logout HTTP 204 ; refresh suivant avec l'ancien refresh_token → HTTP 401 (bien révoqué) | ✅ |
| Anti-brute-force (6ᵉ échec → 429) | `phpunit --filter testSixthFailedLoginAttemptIsThrottled` | 1 test, 6 assertions, OK | ✅ |

### 6. Gestion des clés API (A1.5)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Création d'une clé (token admin) | `curl -X POST /api/api-keys` | HTTP 201, `{"id":5,"key":"nev_...","status":"active","quota":100000,...}` | ✅ |
| Liste des clés de l'utilisateur | `curl GET /api/api-keys` | HTTP 200, liste de 2 clés (celle créée + celle des fixtures), pas de champ `key`/hash exposé | ✅ |
| La clé fonctionne via `X-API-Key` sur un endpoint protégé | `curl /api/auth/me -H "X-API-Key: ..."` | HTTP 200, identité correcte | ✅ |
| Révocation puis rejet de la clé révoquée | `curl -X DELETE /api/api-keys/{id}` puis réutilisation | Révocation HTTP 204 ; réutilisation → HTTP 401 `"Invalid or revoked API key."` | ✅ |

### 7. CI/CD (A1.7)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Le pipeline du dernier commit sur `developp` est vert | vérification GitLab par Serge | *(à remplir par Serge)* | *(à remplir)* |

### 8. Régression globale

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Suite PHPUnit complète au vert (avant correctif Apache) | `phpunit` (env test) | 65 tests, 187 assertions, OK | ✅ |
| Suite PHPUnit complète au vert (après correctif Apache, non-régression) | `phpunit` (env test) | 65 tests, 187 assertions, OK — identique, aucune régression | ✅ |

## Signature

En signant ci-dessous, le Product Owner valide que la Phase A1 (A1.1 à A1.8) est
formellement recettée et que la Phase A2 peut démarrer, conformément à la règle de
gouvernance du cahier des charges (section 10).

- Signataire : *(à remplir)*
- Date : *(à remplir)*
- Décision : *(à remplir — validé / validé avec réserves / non validé)*
