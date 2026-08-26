# A1.8 — Recette Phase A1 (Auth → API → Base de données)

Status: Approved (checklist), execution in progress
Author: Serge (with Claude)
Date: 2026-08-25
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
pipeline status confirmed by Serge, same constraint as A1.7 — no API token available). This
single document starts as a checklist and becomes the filled-in report: **Résultat obtenu**
and **Statut** columns are populated during execution, nothing is pre-filled here.

Order matters: fixtures are loaded early (Auth/API-key checks reuse the demo users it
creates, rather than inventing throwaway data).

## Checklist / Report

### 1. Infrastructure (A1.1)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Les deux conteneurs démarrent et sont sains | `docker compose ps` | *(à remplir)* | *(à remplir)* |

### 2. API skeleton (A1.2)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Endpoint de santé répond | `curl http://localhost:8080/api/health` | *(à remplir)* | *(à remplir)* |
| Documentation OpenAPI accessible et valide | `curl http://localhost:8080/api/doc.json` | *(à remplir)* | *(à remplir)* |

### 3. Schéma de données (A1.3)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Les 3 migrations sont appliquées, aucune nouvelle | `docker compose exec backend php bin/console doctrine:migrations:status` | *(à remplir)* | *(à remplir)* |
| Mapping + synchronisation base valides | `docker compose exec backend php bin/console doctrine:schema:validate` | *(à remplir)* | *(à remplir)* |

### 4. Données de démonstration (A1.6)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Les fixtures se chargent sans erreur | `docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction` | *(à remplir)* | *(à remplir)* |
| Les volumes correspondent au README (54 pays, 5 secteurs, 1080 financements, 3 utilisateurs, 2 clés API) | requêtes SQL de comptage via `dbal:run-sql` | *(à remplir)* | *(à remplir)* |

### 5. Authentification (A1.4)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Login avec identifiants valides → token + refresh_token | `curl -X POST /api/auth/login` (admin de démo) | *(à remplir)* | *(à remplir)* |
| Login avec mauvais mot de passe → 401 | idem, mauvais mot de passe | *(à remplir)* | *(à remplir)* |
| `/api/auth/me` sans token → 401 | `curl /api/auth/me` sans en-tête | *(à remplir)* | *(à remplir)* |
| `/api/auth/me` avec token → 200, rôle correct | `curl /api/auth/me` avec `Authorization: Bearer` | *(à remplir)* | *(à remplir)* |
| Refresh token → nouvelle paire | `curl -X POST /api/auth/refresh` | *(à remplir)* | *(à remplir)* |
| Logout → refresh token révoqué | `curl -X POST /api/auth/logout` puis re-tentative de refresh | *(à remplir)* | *(à remplir)* |
| Anti-brute-force (6ᵉ échec → 429) | suite PHPUnit existante (`AuthenticationControllerTest::testSixthFailedLoginAttemptIsThrottled`) — pas déclenché manuellement sur la base de démo pour ne pas bloquer temporairement un compte réel | *(à remplir)* | *(à remplir)* |

### 6. Gestion des clés API (A1.5)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Création d'une clé (token admin) | `curl -X POST /api/api-keys` | *(à remplir)* | *(à remplir)* |
| Liste des clés de l'utilisateur | `curl GET /api/api-keys` | *(à remplir)* | *(à remplir)* |
| La clé fonctionne via `X-API-Key` sur un endpoint protégé | `curl /api/auth/me -H "X-API-Key: ..."` | *(à remplir)* | *(à remplir)* |
| Révocation puis rejet de la clé révoquée | `curl -X DELETE /api/api-keys/{id}` puis réutilisation | *(à remplir)* | *(à remplir)* |

### 7. CI/CD (A1.7)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Le pipeline du dernier commit sur `developp` est vert | vérification GitLab par Serge (pas de nouveau push déclenché) | *(à remplir)* | *(à remplir)* |

### 8. Régression globale

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Suite PHPUnit complète au vert | `docker compose exec -e APP_ENV=test backend php bin/phpunit` | *(à remplir)* | *(à remplir)* |

## Signature

En signant ci-dessous, le Product Owner valide que la Phase A1 (A1.1 à A1.8) est
formellement recettée et que la Phase A2 peut démarrer, conformément à la règle de
gouvernance du cahier des charges (section 10).

- Signataire : *(à remplir)*
- Date : *(à remplir)*
- Décision : *(à remplir — validé / validé avec réserves / non validé)*
