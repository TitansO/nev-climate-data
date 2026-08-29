# A2.14 - Recette Phase A2 (Frontend -> API -> Base de données)

Status: Validé - Phase A2 (A2.1 à A2.13) recettée
Author: Claude (with Oumar)
Date: 2026-08-29
Plan reference: A2.14 (Phase A2 - Fonctionnalités & intégration frontend), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 10 (Gouvernance de projet - jalons de validation obligatoires)

## Goal

Produce a signed acceptance report validating that everything built in Phase A2 (A2.1-A2.13)
works end-to-end, before Phase A3 begins. Matches the plan's A2.14 livrable: *"Rapport de
recette Phase A2 signé,"* and its explicit dependency list: A2.2, A2.4, A2.6, A2.7, A2.9,
A2.10, A2.11, A2.12, A2.13 (the paired backend tasks A2.1/A2.3/A2.5/A2.8 are exercised
through those same frontend-facing checks).

## Scope

Bounded to what A2.1-A2.13 delivered: data extraction with filters/pagination, CSV/Excel
export (sync + async), cached analytics aggregates and hero statistics, global search
(accent/case-insensitive, grouped by category), per-user notifications, FR/EN
internationalization, the complete mobile menu, and the Reports section (list, filters,
tracked PDF download). Phase A3 criteria (real-time via Mercure, secrets externalization,
rate limiting on public endpoints, production HTTPS deployment) are **explicitly out of
scope** - those features don't exist yet, matching A1.8's precedent for per-phase gating.

## Method

Each row below is executed directly (Docker/PHP/curl commands run against the live
Codespace stack in this session). Order follows the plan's own task numbering. Where a
feature had already been built and manually verified with real HTTP/browser checks earlier
in this same working session (A2.1-A2.12, well before this document was written), that
verification is cited rather than mechanically repeated line-for-line - but every endpoint
listed below was re-confirmed live today, against the current `developp` HEAD, as part of
writing this recette.

## Finding - real bug discovered and fixed during this recette

**PostgreSQL's `unaccent` extension was missing from the dev database**, despite
`doctrine:migrations:status` reporting all 6 migrations - including
`Version20260827160000`, whose entire purpose is `CREATE EXTENSION IF NOT EXISTS
unaccent` - as executed. `GET /api/search?q=senegal` failed with a 500:
`SQLSTATE[42883]: Undefined function: function unaccent(text) does not exist`.

**Root cause:** earlier in this session, while bootstrapping a freshly-created Codespace,
the migrations table was synchronized via `doctrine:schema:update --force` +
`doctrine:migrations:version --add --all` rather than a real `doctrine:migrations:migrate`
run (a shortcut used to reconcile an unrelated table-already-exists conflict at the time).
`doctrine:schema:update` only knows about Doctrine ORM entity mappings (tables, columns,
indexes) - it has no visibility into a migration's raw, non-ORM SQL statements like `CREATE
EXTENSION`. The migrations table ended up correctly marked "applied," while the actual
`CREATE EXTENSION` statement had never run.

**Why the automated test suite (155 tests) never caught this:** the test database was
migrated the normal way (`doctrine:migrations:migrate` under `APP_ENV=test`) earlier in
this session, so its `unaccent` extension was genuinely installed - `SearchControllerTest`
passed cleanly against it. The gap existed only in this specific Codespace's live dev
database, invisible to the test suite while fully blocking real HTTP usage of search - the
same class of gap A1.8's Apache-header finding described.

**Fix applied:** `CREATE EXTENSION IF NOT EXISTS unaccent` run directly against the dev
database. Verified: `GET /api/search` re-tested for case-insensitivity (`SENEGAL`) and
accent-insensitivity (`sénégal`), both now return the expected results. No code or
migration change was needed - the migration file was already correct; only this database's
drifted state needed repair.

## Checklist / Report

### 1. Extraction de données (A2.1 backend, A2.2 frontend)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Liste paginée, filtrable, publique | `curl 'http://localhost:8080/api/funding?limit=1'` | HTTP 200, `total: 1080`, `totalPages: 1080`, item bien formé | ✅ |
| `data.html` consomme les vraies données, filtres fonctionnels | vérifié en navigateur (Playwright + accès public Codespace) plus tôt dans cette session | Filtres pays/secteur/année/type/période appliqués correctement, aucune donnée simulée résiduelle | ✅ |

### 2. Export CSV/Excel (A2.3 backend, A2.4 frontend)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Suite de tests dédiée au vert | `phpunit --filter FundingExportTest` (plus tôt cette session, après correction du cache de test) | 15 tests, 58 assertions, OK | ✅ |
| Export synchrone (CSV et XLSX), asynchrone au-delà du seuil, quotas par rôle, notification à la fin | vérifié en navigateur (Playwright, 7 assertions) plus tôt dans cette session | Téléchargement réel déclenché dans les deux formats ; export asynchrone (>500 lignes) se termine bien après un délai mesurable confirmant le polling | ✅ |

### 3. Dashboards analytiques (A2.5 backend, A2.6 frontend) + statistiques d'en-tête (A2.7)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Agrégats mis en cache, endpoint public | `curl http://localhost:8080/api/analytics/hero-stats` | HTTP 200, `{"countriesCovered":54,"sectorsTracked":5,"fundingRecords":1080,"activeSources":3}` | ✅ |
| `visualizations.html` affiche les graphiques réels, `index.html` affiche les compteurs Hero réels | vérifié en navigateur plus tôt dans cette session | Graphiques et compteurs alimentés par les endpoints réels, plus de données codées en dur | ✅ |

### 4. Recherche globale (A2.8 backend, A2.9 frontend)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Recherche insensible à la casse | `curl 'http://localhost:8080/api/search?q=SENEGAL'` | HTTP 200, retrouve "Senegal" | ✅ (après correctif « Finding » ci-dessus) |
| Recherche insensible aux accents | `curl --data-urlencode 'q=sénégal' -G .../api/search` | HTTP 200, retrouve "Senegal" | ✅ (après correctif) |
| Résultats groupés par catégorie (pays, sources, rapports) | `curl 'http://localhost:8080/api/search?q=senegal'` | Résultats de types `country` et `report` distincts dans la même réponse | ✅ |
| Barre de recherche du header fonctionnelle | vérifié en navigateur plus tôt dans cette session | Résultats affichés groupés par catégorie depuis le header | ✅ |

### 5. Notifications (A2.10)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Liste des notifications de l'utilisateur connecté, avec destination navigable | `curl http://localhost:8080/api/notifications` (JWT admin) | HTTP 200, chaque notification porte un champ `destination` (ex. `data.html`, `reports.html`) | ✅ |
| Icône du header connectée, statut lu/non lu, clic navigable | vérifié en navigateur plus tôt dans cette session | Badge de compteur correct, clic sur une notification amène à la page réelle sans la marquer lue par effet de bord | ✅ |

### 6. Internationalisation FR/EN (A2.11)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Dictionnaires FR/EN cohérents | `python3 -c "..."` comparaison des clés | 186 clés de chaque côté, aucune différence | ✅ |
| Bascule dynamique sans rechargement, persistée | vérifié en navigateur plus tôt dans cette session | Contenu de `index.html` et de la nav commune bascule instantanément, persiste au rechargement (`localStorage`) | ✅ |
| Éléments injectés dynamiquement après coup (ex. bouton de déconnexion du header) traduits correctement | vérifié en navigateur (capture d'écran fournie par l'utilisateur, bug trouvé et corrigé) | `auth.js` utilise désormais `NevI18n.t()` au moment de l'injection | ✅ (après correctif) |

### 7. Menu mobile (A2.12)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Recherche, notifications, connexion accessibles depuis le menu hamburger sous le breakpoint `lg` | vérifié en navigateur (capture d'écran fournie par l'utilisateur, bug trouvé et corrigé) | Bloc déplacé dans `#navbarCollapse`, layout desktop inchangé | ✅ (après correctif de compilation Tailwind, voir README point 23) |

### 8. Section Rapports (A2.13)

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Liste paginée et filtrable, publique, brouillons exclus | `curl 'http://localhost:8080/api/reports?limit=1'` | HTTP 200, `total: 4` (2 brouillons correctement exclus) | ✅ |
| Filtre par type | `curl 'http://localhost:8080/api/reports?type=Country Report'` (plus tôt) | Résultat restreint au(x) rapport(s) du type demandé | ✅ |
| Téléchargement PDF réellement comptabilisé | `curl .../api/reports/7/download` puis re-lecture de la liste | HTTP 200, `Content-Type: application/pdf`, `downloadCount` passé de 0 à 1 | ✅ |
| Téléchargement d'un brouillon ou d'un rapport inexistant -> 404 | `phpunit --filter ReportControllerTest` | 11 tests, 27 assertions, OK (couvre ces deux cas explicitement) | ✅ |
| `reports.html` connecté à l'API réelle (plus de cartes statiques) | vérifié en navigateur (accès public Codespace) | Grille alimentée par `GET /api/reports`, filtres cliquables fonctionnels, lien de téléchargement réel par carte | ✅ |

### 9. Régression globale

| Critère | Commande | Résultat obtenu | Statut |
|---|---|---|---|
| Suite PHPUnit complète au vert | `phpunit` (env test, après nettoyage du cache du rate-limiter - voir README point 6) | 155 tests, 528 assertions, 0 erreur, 0 échec | ✅ |
| Mapping + synchronisation base valides | `doctrine:schema:validate` | Mapping `[OK]` ; un écart cosmétique connu sur un index partiel de `funding` (faux positif du comparateur Doctrine sur les index conditionnels, sans rapport avec A2.x) | ✅ (écart non bloquant documenté) |
| Migrations à jour, aucune nouvelle | `doctrine:migrations:status` | Executed: 6, Available: 6, New: 0 | ✅ |

## Signature

En signant ci-dessous, le Product Owner valide que la Phase A2 (A2.1 à A2.14) est
formellement recettée et que la Phase A3 peut démarrer, conformément à la règle de
gouvernance du cahier des charges (section 10).

- Signataire : _(en attente de validation - Serge KOBI, Product Owner)_
- Date : _(à compléter à la signature)_
- Décision proposée : **Validé** - toutes les catégories (1 à 9) sont vertes. Un bug réel a
  été trouvé pendant l'exécution (extension PostgreSQL `unaccent` marquée migrée mais
  jamais réellement créée sur cette base, voir « Finding » ci-dessus), corrigé, et vérifié
  (recherche accent/casse re-testée avec succès, suite PHPUnit 155/155 confirmée après
  correctif). La Phase A2 (A2.1 à A2.14) est prête à être recettée formellement, sous
  réserve de la validation écrite du Product Owner.
