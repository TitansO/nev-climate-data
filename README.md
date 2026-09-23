# NEV Climate Data

Plateforme de collecte, structuration et diffusion de données climatiques et de financement (Volet A : application ; Volet B : pipeline de données). **Le Volet A (Phases A1, A2, A3) est fonctionnellement complet** : fondations (Docker, API Symfony, schéma TimescaleDB, JWT, clés API, CI/CD), fonctionnalités (extraction filtrée/paginée, export CSV/Excel, dashboards analytiques, recherche globale, notifications, i18n FR/EN, menu mobile, rapports), et qualité/production (temps réel via Mercure, frontend migré en React, secrets externalisés, rate limiting, couverture de tests renforcée, sauvegardes automatisées et restauration testée, tests de charge, mise en production réelle Render+Netlify, documentation Swagger complète) - voir « État d'avancement » en bas de ce document pour le détail, et [`docs/superpowers/specs/2026-09-02-a310-phase-a3-recette.md`](docs/superpowers/specs/2026-09-02-a310-phase-a3-recette.md) pour le rapport de recette final, en attente de validation écrite du Product Owner. Le Volet B (pipeline de données réelles) a démarré en parallèle - voir la section « Pipeline (Volet B) » plus bas.

## Démarrage rapide

Pour un premier clone (local ou Codespace neuf) - détail de chaque étape dans les sections
correspondantes plus bas si besoin :

```bash
cp .env.example .env
# éditer .env : remplacer chaque `change_me_...` par une vraie valeur
# (openssl rand -hex 16 pour les secrets, openssl rand -base64 24 pour les mots de passe)

docker compose up -d --build
docker compose exec backend mkdir -p config/jwt
docker compose exec backend php bin/console lexik:jwt:generate-keypair --overwrite
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction

# vérifier que tout est sain :
curl http://localhost:8080/api/health
docker compose exec backend php bin/console doctrine:schema:validate
```

Attendu : `curl` renvoie `{"status":"ok","service":"NEV Climate Data API"}`, `schema:validate`
affiche deux `[OK]`. La base est vide mais fonctionnelle à ce stade (schéma en place, aucune
donnée) - voir « Scripts de seed / fixtures (A1.6) » pour charger un jeu de démonstration, ou
« Pipeline (Volet B) » pour collecter de vraies données. Reprise d'un environnement existant
(`git pull`) : checklist complète juste en dessous des « Variables d'environnement ».

Aucune installation locale de PHP/Composer/Node n'est nécessaire : tout s'exécute dans les
conteneurs Docker (voir « Prérequis »).

## Structure du dépôt

```
nev-climate-data/
├── backend/                 Application Symfony (API REST)
│   ├── src/
│   │   ├── Controller/      Contrôleurs HTTP (ex: HealthController)
│   │   ├── Entity/          Entités Doctrine (Country, Sector, Source, User, Funding, Report, ApiKey, Notification)
│   │   └── Repository/      Repositories Doctrine
│   ├── config/               Configuration Symfony (routes, packages, bundles)
│   ├── migrations/           Migrations Doctrine (3 : schéma couche 1, couche 2, table refresh_token - voir « Schéma de données »)
│   ├── tests/                Tests automatisés (PHPUnit)
│   ├── public/                Point d'entrée HTTP (index.php)
│   └── composer.json
├── frontend/                 Application statique (HTML + Tailwind CSS v4, sans framework/bundler)
│   ├── *.html                 9 pages (accueil, données, visualisations, rapports, sources, à propos,
│   │                          doc API, connexion, 404) + profil et clés API (voir frontend/README.md)
│   ├── assets/js/              api.js (GET /api/funding), auth.js (session JWT, clés API), main.js (UI)
│   └── src/                    Thème Tailwind (input.css) + CSS compilé
├── pipeline/                  Pipeline de données Volet B (collecteurs, DAGs Airflow, processors Kafka)
├── docker/
│   └── backend/               Dockerfile et configuration Apache du backend
├── docs/
│   ├── exploitation/          Runbooks, suivi des coûts, support de formation (tâches transverses T.1-T.3)
│   └── superpowers/           Specs de conception, plans d'implémentation, HANDOFF
├── docker-compose.yml         Orchestration des services (backend + base de données)
├── .env.example                Modèle des variables d'environnement
└── README.md
```

> Détail complet du frontend (structure, identité visuelle, comment le lancer) : [`frontend/README.md`](frontend/README.md).

## Exploitation (runbooks, coûts, formation)

Documentation opérationnelle, produite au titre des tâches transverses T.1 à T.3 du plan
d'implémentation (voir aussi la section « Tâches transverses (T) » de l'« État d'avancement ») :

| Document | Contenu |
|---|---|
| [`docs/exploitation/runbooks.md`](docs/exploitation/runbooks.md) | **Procédures opérationnelles** : démarrer/arrêter la pile (15 services), reprise après `git pull`, lancer les tests (avec le `-d memory_limit=512M` obligatoire), déclencher les 5 collectes et vérifier qu'elles aboutissent, incidents (téléchargement des rapports en production, valeurs `NaN`), sauvegarde/restauration de la base, circuit de déploiement + vérification CI, réveil et limites du Codespace, contrôles de cohérence dev↔production, réserves ouvertes |
| [`docs/exploitation/suivi-couts.md`](docs/exploitation/suivi-couts.md) | **Suivi des coûts** : tableau mensuel à dupliquer (13 postes), historique, 7 risques de bascule vers un coût non nul (dont l'expiration à 30 jours de la base PostgreSQL gratuite Render), leviers d'optimisation en place, procédure de relevé mensuel. Configuration actuelle : coût monétaire ~nul |
| [`docs/exploitation/formation-equipe.md`](docs/exploitation/formation-equipe.md) | **Support de formation de l'équipe interne** : objectifs pédagogiques, 3 parcours selon le public (métier / analystes / technique), modules (nature des données, lecture des tableaux de bord, pipeline en pratique, gestion d'incident), exercices pratiques, glossaire, journal des sessions à tenir |

## Prérequis

- Docker et Docker Compose (v2)
- Git

Aucune installation locale de PHP/Composer n'est nécessaire : tout s'exécute dans les conteneurs Docker.

## Variables d'environnement

Copier le fichier d'exemple puis adapter les valeurs :

```bash
cp .env.example .env
```

| Variable | Description |
|---|---|
| `APP_ENV` | Environnement Symfony (`dev`, `prod`, `test`) |
| `APP_SECRET` | Secret applicatif Symfony (générer une valeur aléatoire) |
| `POSTGRES_DB` | Nom de la base de données PostgreSQL |
| `POSTGRES_USER` | Utilisateur PostgreSQL |
| `POSTGRES_PASSWORD` | Mot de passe PostgreSQL |
| `POSTGRES_PORT` | Port PostgreSQL exposé sur l'hôte (défaut : `5432`) |
| `BACKEND_PORT` | Port de l'API exposé sur l'hôte (défaut : `8080`) |
| `CORS_ALLOWED_ORIGIN_REGEX` | Motif regex des origines autorisées à appeler `/api/*` depuis un navigateur (A2.1). Défaut : couvre à la fois `http://localhost:8123` (tunnel SSH local) et n'importe quelle URL forwardée de Codespace (`https://<nom>-8123.app.github.dev`) - voir `backend/config/packages/nelmio_cors.yaml`. |

Le fichier `.env` réel n'est **jamais** versionné (voir `.gitignore`). Aucun secret n'est codé en dur dans les fichiers Docker.

> Note : le service `database` utilise l'image `timescale/timescaledb:latest-pg16` (compatible protocole PostgreSQL) depuis le Point 3 du plan (A1.3). Les variables de connexion (`DATABASE_URL`, `POSTGRES_*`) n'ont pas changé lors de ce remplacement.

### Après avoir récupéré ce travail (`git pull`) : checklist de reprise

Si tu avais déjà un environnement local avant ce commit, ces étapes nécessitent une action :

1. **Nouvelle variable d'environnement** : ajoute `JWT_PASSPHRASE=<valeur aléatoire>` à ton `.env` local (voir `.env.example` - génère une valeur avec `openssl rand -hex 16`). Si tu pars d'un environnement tout neuf (premier clone, nouveau Codespace) et que `.env` n'existe pas encore à la racine : `cp .env.example .env`, puis remplace chaque `change_me_...` par une vraie valeur (`openssl rand -hex 16` pour les secrets, `openssl rand -base64 24` pour les mots de passe) - sans ce fichier, `docker compose up -d` échoue sur la base (« superuser password is not specified »).
2. **Reconstruire l'image backend** (nouvelles dépendances Composer - JWT, refresh token, rate limiter, fixtures) :
   ```bash
   docker compose up -d --build backend
   ```
3. **Générer ta propre paire de clés JWT** (jamais versionnée, donc absente après un `git pull`) :
   ```bash
   docker compose exec backend mkdir -p config/jwt
   docker compose exec backend php bin/console lexik:jwt:generate-keypair --overwrite
   ```
4. **Appliquer les 3 migrations** (schéma pipeline-ready + table des refresh tokens) :
   ```bash
   docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
   ```
5. **(Optionnel) Charger un jeu de données de démonstration** - voir « Scripts de seed / fixtures (A1.6) » ci-dessous ; sans cette étape la base est vide mais parfaitement fonctionnelle (schéma en place, aucune erreur).
6. **Vérifier que tout est sain** :
   ```bash
   docker compose exec backend php bin/console doctrine:schema:validate
   docker compose exec -e APP_ENV=test backend php bin/console doctrine:database:create --if-not-exists
   docker compose exec -e APP_ENV=test backend php bin/console doctrine:migrations:migrate --no-interaction
   docker compose exec -e APP_ENV=test backend php bin/phpunit
   ```
   Attendu : `doctrine:schema:validate` → deux `[OK]` ; la suite de tests → 83 tests au vert.

## Démarrer le projet

```bash
docker compose up -d --build
```

Cette commande construit l'image du backend, démarre les conteneurs `backend` et `database`, et rend l'API accessible sur `http://localhost:8080` (ou le port défini par `BACKEND_PORT`).

## Arrêter le projet

```bash
docker compose down
```

Pour arrêter et supprimer également les volumes (⚠️ supprime les données de la base) :

```bash
docker compose down -v
```

## Reconstruire les conteneurs

```bash
docker compose up -d --build
```

## Consulter les logs

```bash
docker compose logs -f
docker compose logs -f backend
docker compose logs -f database
```

## Voir les conteneurs actifs

```bash
docker compose ps
```

## Accéder au backend

```bash
docker compose exec backend bash
```

## Tester l'API

Endpoint de santé :

```bash
curl http://localhost:8080/api/health
```

Réponse attendue :

```json
{
  "status": "ok",
  "service": "NEV Climate Data API"
}
```

## Documentation Swagger / OpenAPI

Une fois les conteneurs démarrés, la documentation interactive de l'API est disponible sur :

```
http://localhost:8080/api/doc
```

La spécification OpenAPI brute (JSON) est disponible sur :

```
http://localhost:8080/api/doc.json
```

**Complétude vérifiée (A3.9, 2026-09-02)** : les 28 opérations exposées par l'API sont maintenant toutes documentées avec leur exigence de sécurité réelle - **vérifié endpoint par endpoint contre le comportement réel** (`security.yaml`), pas seulement supposé cohérent :
- Un vrai bug pré-existant trouvé et corrigé : `App\OpenApi\SecuritySchemes` (les définitions `bearerAuth`/`apiKeyAuth` référencées par tous les endpoints protégés) n'était en réalité **jamais lu** par NelmioApiDocBundle 5.x - confirmé en lisant le code source du bundle (`OpenApiPhpDescriber`/`SecurityDescriber` ne scannent que les classes atteignables depuis une vraie route Symfony, jamais une classe "libre" comme documenté à tort dans le commentaire de cette classe). `components.securitySchemes` était donc vide dans la spec servie - le cadenas "Authorize" de Swagger UI n'avait jamais de définition à laquelle s'accrocher. Corrigé via `nelmio_api_doc.yaml`'s `areas.default.security` (mécanisme officiellement supporté par le bundle pour ce cas précis).
- `POST /api/auth/login` et `POST /api/auth/refresh` (les deux endpoints les plus utilisés) étaient **absents de la documentation** : aucun contrôleur réel ne les gère (interceptés par le firewall avant résolution du contrôleur, voir `config/routes.yaml`), et le describer de Nelmio ignore silencieusement toute route sans contrôleur réfléchissable. Corrigé en pointant ces deux routes vers deux méthodes réelles (mais jamais atteintes en pratique) sur `AuthenticationController` (`login()`/`refreshToken()`), portant les attributs `#[OA\Post]` - vérifié en direct que l'authentification réelle continue de fonctionner exactement comme avant (le firewall intercepte toujours en premier).
- Le mécanisme de sécurité par défaut de Nelmio remplit automatiquement `security` pour toute opération qui ne le déclare pas explicitement - risque réel de marquer à tort des endpoints publics (`/api/funding`, `/api/analytics/*`, `/api/search`, `/api/reports*`, `/api/health`) comme nécessitant une authentification. Corrigé en ajoutant `security: []` explicitement sur les 10 opérations réellement publiques, avant d'activer le mécanisme - vérifié en direct, opération par opération, que la spec finale reflète exactement `security.yaml` (aucun faux positif ni faux négatif).

## Schéma de données

Le schéma métier (section 5.3 du cahier des charges) est réparti en deux migrations Doctrine, correspondant à deux couches de dépendances, plus une troisième migration technique pour l'authentification (A1.4) :

**Couche 1 - sans clé étrangère** (`Version20260824005839`) : `Country`, `Sector`, `Source`, `User` (table `users`, car `user` est un mot réservé en PostgreSQL).

**Couche 2 - dépend de la couche 1** (`Version20260824013715`) : `Funding` (clés étrangères vers `Country`, `Sector`, `Source`), `Report` (clé étrangère optionnelle vers `Country`), `ApiKey` et `Notification` (clé étrangère vers `User`).

**`refresh_token`** (`Version20260824020255`, A1.4) : table technique du bundle d'authentification (Gesdinet), pas une entité métier du cahier des charges - stocke les jetons de rafraîchissement JWT (voir section « Authentification »).

Toutes les valeurs à faible cardinalité et stables (rôle utilisateur, type de financement, statut de validation, statut de rapport, statut de clé API, type/fiabilité de source, type de notification) sont des enums PHP natifs mappés via Doctrine, pas des tables de référence.

`Funding` porte dès maintenant les colonnes nécessaires au Volet B sans que la logique applicative du Volet A les exploite :
- `originalAmount` / `originalCurrency` / `exchangeRate` (montant dans la devise pivot USD dans `amount`, métadonnées de conversion réservées au Volet B)
- `validFrom` / `validTo` / `isCurrent` (hooks d'historisation ; le Volet A continue de faire de simples `UPDATE` en place)

Détails complets du modèle de données et des décisions de conception : voir
[`docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md`](docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md).

> **Note technique - TimescaleDB et `doctrine:migrations:diff`** : `backend/config/packages/doctrine.yaml` définit un `schema_filter` qui exclut les schémas/séquences internes de l'extension TimescaleDB (`_timescaledb_catalog`, `_timescaledb_internal`, etc.) de la comparaison de schéma Doctrine. Sans ce filtre, `doctrine:migrations:diff` et `doctrine:schema:validate` tentent de gérer (voire supprimer) ces objets internes à l'extension. Si une future migration générée contient malgré tout des `CREATE SCHEMA`/`DROP SEQUENCE` sur des objets `timescaledb*`/`_timescaledb*`, les retirer manuellement avant d'appliquer la migration.

### Appliquer les migrations

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate
```

### Voir l'état des migrations

```bash
docker compose exec backend php bin/console doctrine:migrations:status
```

### Valider le schéma (mapping + synchronisation base)

```bash
docker compose exec backend php bin/console doctrine:schema:validate
```

## Tests automatisés

Le conteneur `backend` tourne avec `APP_ENV=dev` (variable réelle injectée par Docker Compose), qui prend le pas sur la configuration de PHPUnit. Il faut donc la surcharger explicitement pour lancer les tests en environnement `test` :

```bash
docker compose exec -e APP_ENV=test backend php bin/phpunit
```

### Couverture de code (A3.5)

Base mesurée le 2026-09-02 (`pcov`, non installé par défaut - voir plus bas) : **186 tests, 77% de couverture en lignes** (`Lines: 77.00% (1058/1374)`, avant l'ajout des 7 tests listés ci-dessous - 193 au total ensuite). Quasiment tous les contrôleurs/services/security sont à 100% - le detail complet (`bin/phpunit --coverage-text`) permet de repérer une régression de couverture avant qu'elle ne devienne un vrai trou.

**Écarts réels comblés pour A3.5** (audité en comparant `find src -name "*.php"` à l'inventaire des classes du rapport de couverture - méthode à réutiliser pour tout futur audit) :
- `App\MessageHandler\GenerateExportMessageHandler` (l'entrée réelle que Messenger appelle en production, A2.3 "export" - un des 3 domaines explicitement cités par le plan pour A3.5) n'avait aucune couverture propre : `tests/Controller/FundingExportTest.php` appelait `ExportService::processAsyncExport()` directement, contournant le handler. Corrigé en faisant passer ce test par le vrai handler (`static::getContainer()->get(GenerateExportMessageHandler::class)(...)`) plutôt que d'ajouter un test dupliqué.
- `App\EventListener\ApiExceptionListener` (A2.1) : la branche `\Throwable` générique (le code qui empêche une erreur 500 de fuiter son message interne) n'était exercée par aucun test - seule la branche `HttpExceptionInterface` l'était, indirectement, par chaque contrôleur. `tests/EventListener/ApiExceptionListenerTest.php` couvre maintenant les deux branches explicitement, plus une régression pour la préservation des en-têtes (`Retry-After`, A3.4).
- `App\Command\PublishKpiSnapshotCommand` (A3.1) n'avait aucun test. `tests/Command/PublishKpiSnapshotCommandTest.php` - `AnalyticsService` étant `final` sans interface (non mockable), utilise le vrai service résolu du conteneur, seul `HubInterface` (une vraie interface) est simulé.

**Deux classes `final` sans interface rencontrées pendant cet audit (`ExportService`, `AnalyticsService`)** : PHPUnit ne peut pas générer de double de test pour une classe `final` (`createMock()`/`createStub()` génèrent une sous-classe, ce que PHP interdit pour `final`). Pattern retenu à chaque fois : soit résoudre le vrai service depuis le conteneur (`KernelTestCase`/`WebTestCase`, cohérent avec la philosophie déjà établie du projet - voir le commentaire d'`AnalyticsControllerTest`, "test the real behavior... not a simulation"), soit appeler directement le vrai code de production plutôt que dupliquer un test qui existe déjà à un niveau différent (le cas `FundingExportTest`). Ne jamais retirer `final` juste pour rendre une classe mockable.

**CI (`.gitlab-ci.yml`)** : `pecl install pcov` ajouté au `before_script` (jamais dans `docker/backend/Dockerfile` - l'instrumentation de couverture n'a rien à faire dans une image qui sert aussi du vrai trafic), `bin/phpunit --coverage-text --coverage-cobertura=coverage.cobertura.xml`. Le job expose un `coverage_report` Cobertura (GitLab affiche alors la couverture par ligne modifiée directement dans le diff d'une MR) et une regex `coverage:` pour le badge de pipeline. Vérifié en direct (génération du XML, format valide) avant de committer - pas seulement supposé fonctionner.

## Connexion à la base de données

La connexion Symfony → PostgreSQL est configurée via la variable `DATABASE_URL`, injectée automatiquement dans le conteneur `backend` par `docker-compose.yml` à partir des variables `POSTGRES_*` du fichier `.env`.

Pour vérifier manuellement la connexion :

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT 1 AS ok"
```

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
| `POST /api/auth/logout` | Oui (Bearer token) | Révoque le refresh token de l'utilisateur - 204 |

### Durées de vie

- Access token : 15 minutes
- Refresh token : 30 jours, à usage unique (rotation à chaque `/api/auth/refresh`)

### Protection anti-brute-force

5 tentatives de connexion échouées par identifiant sur une fenêtre de 15 minutes déclenchent un blocage temporaire (`429 Too Many Requests`).

### Rôles

`User.role` (`Admin` / `InternalAnalyst` / `ExternalPartner`) est mappé vers `ROLE_ADMIN` / `ROLE_INTERNAL_ANALYST` / `ROLE_EXTERNAL_PARTNER` (+ `ROLE_USER` pour tout utilisateur authentifié). Le "Visiteur" du cahier des charges correspond à l'absence de compte, donc à l'absence de jeton - pas à un rôle stocké en base.

## Gestion des clés API (A1.5)

Chaque utilisateur authentifié peut générer, lister et révoquer ses propres clés API (cahier des charges 5.2.b). Détails de conception : voir le rapport final A1.5 dans l'historique de conversation du projet (pas de fichier spec dédié pour cette tâche).

### Principe de sécurité

- La clé brute (`nev_<64 caractères hex>`, 256 bits d'entropie via `random_bytes`) n'est **retournée qu'une seule fois**, à la création. Elle n'est jamais stockée : seul son hash SHA-256 l'est (colonne `key_hash`), et aucun endpoint ne le renvoie jamais.
- Une clé révoquée est immédiatement refusée par la validation, mais reste en base (traçabilité) avec `status = revoked` et `revoked_at` renseigné.

### Endpoints (authentification JWT requise - voir « Limites actuelles » ci-dessous)

| Endpoint | Description |
|---|---|
| `POST /api/api-keys` | Génère une nouvelle clé pour l'utilisateur connecté. Réponse `201` avec `key` (clé brute, à sauvegarder immédiatement). |
| `GET /api/api-keys` | Liste les clés de l'utilisateur connecté (métadonnées seulement - jamais `key` ni le hash). |
| `DELETE /api/api-keys/{id}` | Révoque une clé appartenant à l'utilisateur connecté. `404` si la clé n'existe pas ou appartient à quelqu'un d'autre (pas de `403`, pour ne pas révéler l'existence de la clé d'un tiers). |

### Utiliser une clé API

Envoyer la clé dans l'en-tête `X-API-Key` sur n'importe quelle route `/api/*` :

```bash
curl -H "X-API-Key: nev_<votre_clé>" http://localhost:8080/api/auth/me
```

L'authentification par clé API (`App\Security\ApiKeyAuthenticator`) est enregistrée sur le firewall `api` aux côtés de JWT (`security.yaml`) - les deux mécanismes coexistent, chacun ne réagissant qu'à son propre en-tête (`Authorization: Bearer` vs `X-API-Key`).

**Important - les endpoints `/api/api-keys` eux-mêmes exigent une authentification JWT et refusent une clé API** (`403`) : une clé API compromise ne doit pas pouvoir en générer d'autres. C'est une politique volontairement plus stricte que le reste de l'API, documentée dans `App\Controller\ApiKeyController::assertJwtAuthenticated()`.

### Quotas par rôle

| Rôle | Quota (requêtes/jour) |
|---|---|
| Admin | 100 000 |
| Analyste interne | 20 000 |
| Partenaire externe | 5 000 |

⚠️ **Ces valeurs sont provisoires** : ni le cahier des charges ni le plan d'implémentation ne fixent de chiffres officiels (la spec A1.3 précise seulement que `quota` est "a daily request quota"). Elles sont centralisées dans `App\Security\ApiKeyQuotaPolicy` - à ajuster dès que le client valide des valeurs définitives.

### Limites actuelles

- **Le quota n'est pas encore appliqué en usage réel** : la colonne `ApiKey.quota` porte la limite attribuée à la création, mais rien ne compte ni ne décrémente les requêtes consommées à ce stade - le schéma actuel ne porte aucun compteur d'usage ni fenêtre temporelle. L'attribution du quota par rôle est fonctionnelle ; son application (rejet au-delà du quota) reste à concevoir, probablement en A2 ou A3, avec une réflexion sur le stockage (compteur en base vs. Redis avec fenêtre glissante).
- **Aucune gestion des clés d'un autre utilisateur par un admin** n'est implémentée : le cahier des charges ne définit pas encore cette politique: seule la gestion de ses propres clés est disponible.
- **Aucune route métier n'accepte encore la clé API** au-delà de l'infrastructure elle-même : le firewall accepte `X-API-Key` sur tout `/api/*`, validé par les tests contre `/api/auth/me`. `GET /api/funding` (A2.1, ci-dessous) est public et ne nécessite donc aucune authentification, JWT ou clé API - le premier endpoint de données à accepter effectivement une clé API reste à venir.

## Données de financement (A2.1)

`GET /api/funding` - liste paginée et filtrable des financements climatiques, **en accès public** (aucune authentification requise, conforme à la section 5.2 du cahier des charges).

### Filtres (tous optionnels, combinables)

| Paramètre | Format | Effet |
|---|---|---|
| `country` | code ISO (ex: `SEN`) | Filtre par pays |
| `sector` | id numérique | Filtre par secteur |
| `year` | année (ex: `2025`) | Filtre par année |
| `fundingType` | `public` \| `private` \| `multilateral` | Filtre par type de financement |
| `periodStart` / `periodEnd` | `YYYY-MM-DD` | Filtre par date de collecte (bornes incluses) |
| `page` | entier positif | Page demandée (défaut : `1`) |
| `limit` | entier positif | Taille de page (défaut : `20`, plafonné à `100`) |

Toute valeur invalide (enum inconnu, date mal formée, `periodStart` postérieur à `periodEnd`, `page`/`limit` non positifs) renvoie `400` avec un message explicite, au format JSON uniforme (`{"code": ..., "message": ...}`) sur toute erreur `/api/*` (`App\EventListener\ApiExceptionListener`).

### Exemple

```bash
curl "http://localhost:8080/api/funding?country=SEN&year=2025&page=1&limit=10"
```

```json
{
  "data": [
    { "id": 1, "country": {"name": "Senegal", "isoCode": "SEN"}, "sector": {"id": 3, "name": "Agriculture"}, "year": 2025, "amount": "125000.00", "fundingType": "public", "source": {"id": 1, "name": "..."}, "collectionDate": "2025-03-15", "validationStatus": "demo" }
  ],
  "meta": { "page": 1, "limit": 10, "total": 1080, "totalPages": 108 }
}
```

### CORS

Le frontend (autre origine que le backend) appelle cette API depuis le navigateur : `nelmio/cors-bundle` autorise `/api/*` pour les origines couvertes par `CORS_ALLOWED_ORIGIN_REGEX` (voir tableau des variables d'environnement plus haut) - jamais `*`.

### Correction du filtrage isCurrent (B1.7, 2026-08-31)

Un vrai bug de production a été trouvé en creusant B1.7 (gestion des conflits entre sources et
historisation) : aucune requête de lecture de `Funding` ne filtrait `isCurrent = true`, mélangeant
lignes courantes et versions historisées dans le listing, l'export CSV, les agrégats analytics
(tendances de financement, répartition sectorielle) et les stats du Hero - un gonflement mesuré
d'environ 65% sur tous les chiffres affichés. Décisions complètes :
[`docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md`](docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md).

Corrigé dans `FundingRepository` (chaque méthode de lecture) et `AnalyticsService::getHeroStats()`
- le mécanisme d'historisation écrit par les processors Volet B (`upsert_funding()`) est
maintenant respecté de bout en bout par l'API qui le lit.

## Scripts de seed / fixtures (A1.6)

Un jeu de données de démonstration cohérent et reproductible, chargé via `doctrine/doctrine-fixtures-bundle` (dépendance `dev`/`test` uniquement - jamais chargée en `prod`).

### Prérequis

Aucune installation supplémentaire : `doctrine/doctrine-fixtures-bundle` est déjà dans `composer.json` (`require-dev`). Après un `git pull`, reconstruire l'image backend si ce n'est pas déjà fait (voir la checklist de reprise plus haut) - `composer install` s'exécute automatiquement au build.

### Charger les fixtures

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction
```

**Purge et recharge** : la commande ci-dessus **purge déjà** la base avant de recharger (comportement par défaut de `doctrine:fixtures:load`) - pas de commande séparée nécessaire. Rejouable autant de fois que nécessaire : toutes les valeurs sont calculées par des formules déterministes (aucun `rand()`/Faker), donc chaque rechargement reproduit exactement les mêmes lignes.

### Contenu du jeu de données

| Entité | Nombre | Détail |
|---|---|---|
| `Country` | **54** | Les 54 pays membres de l'ONU en Afrique, classés en 5 régions (Afrique du Nord, de l'Ouest, centrale, de l'Est, australe) - liste explicite dans `CountryFixtures.php`, pas générée |
| `Sector` | **5** | Renewable Energy, Sustainable Transport, Agriculture, Forestry, Adaptation - repris tels quels des exemples déjà présents dans `docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md` |
| `Source` | 4 | Une par valeur de `SourceType` (API officielle, rapport PDF, évènement GreenAccess, démonstration interne) |
| `User` | 3 | Un par rôle (`Admin`, `InternalAnalyst`, `ExternalPartner`) |
| `Funding` | **1 080** | 54 pays × 5 secteurs × 4 années (2022-2025) - voir « Volume et répartition » |
| `Report` | 6 | Mélange rapports globaux / régionaux / par pays, statuts `Draft`/`Published` |
| `Notification` | 7 | Réparties sur les 3 utilisateurs, mélange lu/non lu |
| `ApiKey` | 2 | Une active, une révoquée - juste de quoi tester la relation avec `User` |

### Volume et répartition des données Funding

**Toutes les données `Funding` de ces fixtures utilisent `ValidationStatus::Demo`** - jamais `ValidationStatus::Validated` (règle 5.7 du cahier des charges : jeu de démonstration marqué comme tel).

- **Plage d'années** : 2022-2025 (4 années). Aucune plage officielle n'étant définie dans le cahier des charges ni le plan, ce choix est documenté ici comme provisoire.
- **Volume** : 1 080 lignes = 54 pays × 5 secteurs × 4 années, en couverture complète (pas d'échantillonnage) - choisi plutôt qu'une plage d'années plus longue précisément pour garder ce produit croisé rapide à charger tout en couvrant chaque pays/secteur sur une série temporelle complète (utile pour les futurs graphiques de tendance).
- **Répartition par type** : exactement 360 `Public` / 360 `Private` / 360 `Multilateral` (rotation déterministe selon pays × secteur × année).
- **Montants** : formule déterministe (base par secteur × facteur pays × croissance annuelle × multiplicateur de type), jamais de `float` PHP - toujours une chaîne formatée à 2 décimales, conforme au typage `DECIMAL` de la colonne. Ce sont des montants illustratifs, pas des données financières réelles.
- **Conversion de devise** : `originalAmount`/`originalCurrency`/`exchangeRate` renseignés uniquement sur les financements `Multilateral` (EUR, taux fixe illustratif 1,08) pour exercer ces champs réservés au Volet B, sans prétendre représenter un vrai taux de change.

### Utilisateurs de démonstration

⚠️ **Environnement local/développement uniquement - jamais de secret réel.**

| Rôle | Email | Mot de passe |
|---|---|---|
| SuperAdmin | `superadmin@nev-climate-data.demo` | `ClimateDemo2026!` |
| Admin | `admin@nev-climate-data.demo` | `ClimateDemo2026!` |
| Analyste interne | `analyste@nev-climate-data.demo` | `ClimateDemo2026!` |
| Partenaire externe | `partenaire@nev-climate-data.demo` | `ClimateDemo2026!` |

**SuperAdmin** : seul rôle habilité à gérer les comptes (créer un utilisateur, changer un rôle, supprimer un compte) via `/api/users` (backend) et `account-users.html` (frontend, accessible depuis "Gérer les utilisateurs" sur `account-profile.html` quand on est connecté en SuperAdmin). Un SuperAdmin ne peut ni modifier son propre rôle ni supprimer son propre compte (protection anti-verrouillage - voir la docblock d'`App\Controller\UserController`).

Mot de passe hashé via `password_hash()` (jamais stocké en clair). Les clés API de démonstration (`ApiKeyFixtures.php`) sont générées à partir de valeurs brutes fixes et non secrètes, hashées avec `ApiKeyService::hashKey()` (le même algorithme qu'en production) - **ces clés brutes ne sont pas exploitables** (non documentées, non fonctionnelles pour un usage réel) et ne sont volontairement pas reproduites ici ; pour obtenir une clé utilisable, générer la vôtre via `POST /api/api-keys` après connexion.

### Limites actuelles

- Le volume de `Funding` (1 080 lignes) est pensé pour la démonstration et le développement des dashboards - pas pour un test de charge.
- Les montants et taux de change sont illustratifs, générés par formule, pas des données réelles.
- Pas de fixtures pour `RefreshToken` (entité technique, générée uniquement par le mécanisme d'authentification - jamais de faux token créé pour remplir la base).

## Frontend (A2.2 + intégration de l'authentification)

Application statique HTML + Tailwind CSS v4 (pas de framework/bundler), dans `frontend/`. Détail complet (structure, identité visuelle, comment lancer/compiler) : [`frontend/README.md`](frontend/README.md).

### Données réelles (A2.2)

La page `data.html` consomme `GET /api/funding` (A2.1, ci-dessus) : filtres (pays, secteur, année, type de financement, période), pagination, et 4 états d'interface (chargement, erreur, vide, données) - plus aucune donnée simulée dans le tableau.

### Authentification côté client

Ajoutée en marge du plan d'implémentation, entre A2.2 et A2.3 (justification : A2.3 - export par rôle - et le futur A2.10 - notifications par utilisateur - en ont besoin ; autant la construire une fois plutôt que la retrofitter plus tard) :

- **`login.html`** - formulaire réel branché sur `POST /api/auth/login`.
- **`assets/js/auth.js`** - session JWT/refresh en `localStorage`, rafraîchissement automatique sérialisé (protège le refresh token à usage unique d'une race condition si deux requêtes expirent en même temps), `authorizedFetch()` (retry automatique une fois après un 401).
- **`account-profile.html`** (« Mon profil ») - via `GET /api/auth/me`.
- **`account-api-keys.html`** (« Mes clés API ») - CRUD complet contre `POST/GET/DELETE /api/api-keys` (A1.5), qui n'avait jamais eu d'interface avant.
- Navbar dynamique sur les 9 pages existantes (Connexion ↔ email + Déconnexion).

### Base de l'URL de l'API

`assets/js/api.js` et `assets/js/auth.js` déduisent l'origine du backend de celle de la page elle-même (`window.location.hostname`), plutôt qu'une valeur codée en dur - nécessaire car ce projet est vu depuis deux environnements différents avec des origines différentes : le tunnel SSH local (`http://localhost:8123` → backend `http://localhost:8080`) et l'URL forwardée d'un Codespace (`https://<nom>-8123.app.github.dev` → backend déduit en `https://<nom>-8080.app.github.dev`). Aucun mécanisme de build/injection de variables d'environnement n'existe côté frontend (HTML statique, pas de bundler), donc cette déduction dynamique est la seule solution qui fonctionne sans édition manuelle par environnement.

### Internationalisation FR/EN (A2.11)

Mécanisme léger, cohérent avec le reste du frontend (pas de bundler, module en espace de noms global `window.NevI18n`, voir `assets/js/i18n.js`) :

- **Dictionnaires** : `assets/i18n/fr.json` et `assets/i18n/en.json` - une seule paire de fichiers plats (clé → texte), parité de clés vérifiée (185 clés de chaque côté).
- **Convention côté HTML** : `data-i18n="cle"` (remplace `textContent`), `data-i18n-html="cle"` (remplace `innerHTML` - réservé aux rares textes statiques contenant du balisage, ex. un `<br>` dans le titre du hero), `data-i18n-placeholder="cle"` / `data-i18n-aria-label="cle"` pour les attributs correspondants.
- **Sélecteur de langue** : bouton `#lang-switch-btn` dans le header (affiche la langue cible : « EN » quand on est en français, « Français » quand on est en anglais). Bascule instantanée (pas de rechargement), langue persistée dans `localStorage` (`nev_lang`), défaut `fr`.
- **Couverture actuelle** : les 13 pages (nav commune, footer commun, contenu propre à chaque page) - `data-i18n`/`data-i18n-html` sur du HTML statique au départ, `t(cle, repli)` dans le JSX depuis la migration React (A3.1/A3.2), même dictionnaires JSON, même comportement. Le contenu injecté dynamiquement à l'exécution (lignes du tableau de données, éléments de notification, libellés de rôle sur `account-profile.html`, texte du bouton d'export pendant le traitement) n'est **pas** traduit par ce mécanisme - hors périmètre pour l'instant.
- Pour ajouter une nouvelle chaîne traduisible : poser l'attribut `data-i18n` sur l'élément, ajouter la même clé dans les deux fichiers JSON.

### Menu mobile (A2.12)

Le mécanisme de bascule du menu hamburger (`#navbarToggler` / `#navbarCollapse`, voir `assets/js/main.js`) existait déjà mais le panneau mobile n'affichait que les liens de navigation : recherche, notifications et connexion étaient dans un bloc `hidden … sm:flex` invisible sous 640px et jamais inclus dans le menu ouvert. Ce bloc a été déplacé à l'intérieur de `#navbarCollapse` (empilé sous les liens en mobile ; remis en ligne à droite via `lg:flex lg:justify-between` en desktop, layout desktop inchangé) sur les 10 pages qui le partagent.

**Piège à ne pas réintroduire** : à l'époque de A2.12 (frontend encore 100% HTML statique), Tailwind CSS v4 était compilé à l'avance dans `frontend/src/css/tailwind.css` par le CLI Tailwind, et une classe ajoutée sans relancer `npm run build` n'avait aucun effet visible - c'est ce qui avait cassé le rendu du header une première fois. Ce pipeline CLI séparé a depuis été retiré (2026-09-01, la migration React de la section suivante étant désormais terminée) : chaque page importe directement `src/input.css`, compilé par le plugin `@tailwindcss/vite` dans le même build Vite que le JS - un seul `npm run build` (= `vite build`) produit tout, plus aucun fichier CSS pré-compilé séparé à tenir synchronisé.

### Migration React (A3.1/A3.2, terminée)

Le plan d'implémentation exige explicitement pour A3.2 des "composants React abonnés au flux Mercure", alors que le frontend est en HTML statique + JS simple depuis le début du projet. Décision (2026-09-01) : migrer progressivement tout le frontend vers React plutôt que d'introduire React seulement pour les dashboards, tout en ne cassant aucune page déjà fonctionnelle.

**Architecture retenue** : Vite en mode "Multi-Page App" (`frontend/vite.config.js`), pas une SPA - chaque page migrée reste sa propre URL `.html`, chargée en navigation complète (pas de routage client React Router). Le build Vite écrit **en place** (`outDir: "."`, `emptyOutDir: false`) : il ne touche que les pages explicitement listées dans `rollupOptions.input` et leurs bundles (`assets/react/`), sans jamais toucher les pages pas encore migrées ni leurs `assets/js/*.js` - les deux mondes coexistent tant que la migration n'est pas terminée. `@tailwindcss/vite` réutilise **le même** `src/input.css` que le pipeline Tailwind CLI existant (tokens `@theme`, règles `.sticky`, etc. inchangés).

**`frontend/package.json`** : `npm run build` = `rm -rf assets/react && vite build` (commence par vider le dossier de sortie pour ne jamais accumuler de bundles orphelins d'un lot précédent). Les scripts `build:tailwind`/`dev:tailwind` (CLI Tailwind séparé) et la dépendance `@tailwindcss/cli` ont été retirés une fois les 13 pages migrées (2026-09-01) - devenus inutiles, plus aucune page ne chargeant `src/css/tailwind.css`.

**Piège réel rencontré et corrigé (2026-09-01)** : les pages migrées (`404.html`, `login.html`, ...) restent commitées dans git en **forme source** - `<script type="module" src="./src/react/entries/404.jsx">`, jamais la référence au bundle haché (`/assets/react/404-<hash>.js`). `frontend/assets/react/` (le dossier de sortie du build Vite) est gitignoré, exactement comme `frontend/dist/`/`frontend/build/` l'étaient déjà en réserve - jamais commité. Committer la version déjà construite d'une page (comme fait par erreur une première fois) casse tout rebuild ultérieur : Vite tente alors de résoudre la référence hachée déjà présente dans le HTML au lieu du vrai fichier source, échoue, puisque `assets/react/` vient justement d'être vidé par `rm -rf`. Contrairement à `input.css`/`tailwind.css` (deux fichiers distincts, l'un peut rester commité sans gêner l'autre), le modèle "build en place" de Vite écrit *dans le même fichier* que la source (`404.html` reste `404.html`) - la version buildée ne doit donc **jamais** être commitée, uniquement régénérée à la demande (`npm run build`).

**Fondations partagées** (`frontend/src/react/`) : `providers/AuthProvider.jsx` et `providers/I18nProvider.jsx` portent `assets/js/auth.js`/`i18n.js` en contexts React (mêmes endpoints, même `localStorage`, mêmes dictionnaires JSON `assets/i18n/*.json` réutilisés tels quels) ; `components/Navbar.jsx`/`Footer.jsx`/`GlobalSearch.jsx`/`NotificationBell.jsx`/`AuthSlot.jsx` remplacent le HTML dupliqué et les modules `assets/js/search.js`/`notifications.js` sur chaque page. `Navbar` accepte une prop `variant` (`"standard"` par défaut - recherche + cloche + slot connexion ; `"bare"` - juste les liens de nav, pour `login.html`/`404.html`). **Piège de fidélité à surveiller à chaque page migrée** : `src/input.css` cible certains éléments par ID (`#navbarCollapse`, `#navbarToggler`, `#lang-switch-btn`) - ces IDs doivent être préservés dans le JSX, sinon les règles CSS existantes (dont le fix mobile-dropdown text-color) cessent silencieusement de s'appliquer.

**Charts et carte (Lot 5, `visualizations.html`)** : Chart.js et jsVectorMap sont passés de balises `<script src="cdn...">` à de vraies dépendances npm (`chart.js`, `jsvectormap`), un composant par graphique (`src/react/components/charts/`) créant/détruisant son instance Chart.js dans un `useEffect`. jsVectorMap est particulier : son build npm (`dist/jsvectormap.esm.js`, résolu automatiquement par le champ `exports` du package pour un `import`) pose lui-même `window.jsVectorMap` en effet de bord au chargement, et son fichier `dist/maps/world.js` (les données de la carte) est un script non-module qui lit ce même global - importé en second via un `import()` dynamique séquentiel (pas deux `import` statiques : leur ordre d'évaluation n'est pas garanti suivre l'ordre du texte source) pour être sûr que le global existe déjà quand `world.js` s'exécute.

**Déploiement pendant la migration** : le build Netlify a été désactivé (2026-09-01, ressources limitées) - la vérification se faisait sur le Codespace (`npm install && npm run build`, servi en local sur le port 8123, backend sur 8080, tous deux en visibilité publique via `gh codespace ports visibility`). Le build Netlify a été réactivé le 2026-09-02 (A3.8) une fois la migration terminée - voir « A3.8 - Mise en production (Render + Netlify) » plus bas pour l'état réel en production.

**État** : les 13 pages sont migrées (Lots 0 à 5), et A3.1/A3.2 sont faits - voir la section dédiée ci-dessous.

### A3.1 - Hub Mercure temps réel & A3.2 - abonnement React

**A3.1 (backend)** : `composer require symfony/mercure-bundle` (génère `backend/config/packages/mercure.yaml` et le bloc `.env` habituel `MERCURE_URL`/`MERCURE_PUBLIC_URL`/`MERCURE_JWT_SECRET`, suivant la convention `%env(...)%` déjà utilisée par `REDIS_DSN`/`MESSENGER_TRANSPORT_DSN`). Deux nouveaux services `docker-compose.yml` :
- **`mercure`** - image officielle `dunglas/mercure`, port `3000`. Configuré en `anonymous` + `cors_origins *` (via `MERCURE_EXTRA_DIRECTIVES`) : le seul topic publié ici est déjà une donnée publique (mêmes chiffres que `GET /api/analytics/hero-stats`, `PUBLIC_ACCESS`) - aucune raison d'exiger un JWT d'abonné, qu'un `EventSource` natif ne pourrait de toute façon pas envoyer (pas d'en-tête `Authorization` personnalisé possible). `SERVER_NAME: ':80'` évite que Caddy (le serveur sous-jacent du hub) tente une HTTPS automatique sans nom de domaine public réel.
- **`mercure-publisher`** - même image que `backend`, commande `php bin/console app:mercure:publish-kpis` (`backend/src/Command/PublishKpiSnapshotCommand.php`). **Problème réel identifié à l'exploration** : rien dans ce backend ne détecte un changement de donnée (le pipeline Python du Volet B écrit directement en TimescaleDB via sa propre connexion SQLAlchemy, hors du process Symfony - aucun hook Doctrine possible). Solution retenue, assumée comme telle dans le code : une republication périodique (toutes les 60s par défaut, `--interval`) d'un instantané des KPIs (`fundingTotalUsd` + `countriesCovered`, réutilisant `AnalyticsService::getFinancingTrends()`/`getHeroStats()` - jamais une deuxième source de vérité qui pourrait diverger de l'API REST), pas un vrai événement de changement. Les deux fichiers `backend/compose.yaml`/`compose.override.yaml` générés automatiquement par Flex (sa propre convention Docker Compose, différente - volumes nommés, Caddyfile "dev" avec healthcheck HTTPS) ont été supprimés : ce projet a toujours un unique `docker-compose.yml` à la racine, géré à la main.

**A3.2 (frontend)** : `frontend/src/react/hooks/useMercureKpis.js` - `EventSource` natif (aucune librairie tierce), câblé dans le bandeau KPI de `VisualizationsPage.jsx` (badge « Direct » + remplacement du total de financement dès qu'un instantané arrive). Échoue **soft** par conception : `resolveMercureHubUrl()` calcule l'URL du hub à partir de `window.location.hostname` (même schéma que `resolveApiBaseUrl()` - localhost / Codespace / `null` en production, aucun hub n'y étant déployé pour l'instant) ; si le hub est injoignable, `EventSource` ne se connecte simplement jamais et la page continue de fonctionner sur les agrégats REST existants - vérifié en direct dans les deux états (hub éteint : page fonctionnelle avec `ERR_CONNECTION_REFUSED` en tâche de fond ; hub démarré : badge « Direct » visible, aucune erreur console).

**Vérifié en direct sur le Codespace** (navigateur headless réel, Puppeteer) : `curl` sur `/.well-known/mercure` sans topic → 400 (topic requis), avec le topic KPI → 200 (abonnement anonyme accepté) ; `mercure-publisher` publie avec succès toutes les 60s (log : « Instantané publié (financement total : 3241719990 USD, 54 pays) ») ; `docker compose exec backend php bin/console lint:container` OK ; `AnalyticsControllerTest` (les 16 tests, seule suite dont le code réutilisé par le publisher dépend) passe à 100%.

**Hors périmètre pour l'instant** : déploiement du hub en production (Render ne fait tourner qu'un seul service aujourd'hui - point d'attention #38 - un hub Mercure nécessiterait un 2e/3e service déployé, décision séparée côté coût/plan Render, non prise à ce stade - `useMercureKpis()` continue de fonctionner en mode dégradé/REST en production pour cette raison).

### A3.3 - Secrets externalisés & A3.4 - Rate limiting général

**A3.3 (coffre-fort Symfony)** : décision utilisateur explicite (2026-09-02, entre trois options - coffre Symfony intégré / SaaS dédié type Doppler/Infisical / coffre natif Render) - `symfony/secrets` (déjà inclus avec le framework, aucune dépendance ni coût supplémentaire). Périmètre réel identifié à l'exploration : `APP_SECRET`/`JWT_PASSPHRASE`/`MERCURE_JWT_SECRET` sont les seuls secrets consommés par le processus Symfony lui-même (`grep` confirmé : aucun fichier `backend/src/*.php` ne référence `IATI_API_KEY`/`GEMINI_API_KEY` - ce sont des secrets du pipeline Python du Volet B, jamais lus par PHP, donc hors de portée d'un coffre Symfony ; `POSTGRES_PASSWORD`/`MINIO_ROOT_PASSWORD` amorcent d'autres conteneurs Docker et sont interpolés directement dans `docker-compose.yml` avant même que Symfony démarre - également hors de portée). Ces trois-là restent sur le `.env` racine (gitignoré, jamais dans le code) exactement comme avant.

- `bin/console secrets:generate-keys --env=prod` : paire de clés dédiée à l'environnement `prod` (jamais dev - la clé de déchiffrement de dev resterait `config/secrets/dev/...` si on en générait une, non fait ici puisque dev/Codespace continue de fonctionner sur son `.env` habituel, inchangé). `config/secrets/prod/prod.encrypt.public.php` (clé publique, sert à chiffrer de nouveaux secrets sans jamais avoir besoin de la clé privée) est commité. `config/secrets/prod/prod.decrypt.private.php` (clé privée - la seule chose qui doit maintenant être gérée hors du dépôt) est gitignoré par le squelette Symfony par défaut (`backend/.gitignore`, déjà présent avant A3.3) - copié en lieu sûr hors du dépôt (a atterri en double aujourd'hui : Codespace éphémère + une copie locale, aucune des deux commitée), à fournir au vrai environnement de production (A3.8) via la variable d'environnement `SYMFONY_DECRYPTION_SECRET` (contenu du fichier, pas le fichier lui-même) plutôt que de recopier le fichier physiquement.
- `bin/console secrets:set NOM --env=prod` (valeur généré via `openssl rand -hex 32`, jamais réutilisée de dev/Codespace - la prod doit avoir ses propres secrets, distincts) pour les 3 secrets. Chiffrés dans `config/secrets/prod/*.php`, sûrs à commiter (illisibles sans la clé privée).
- **Comment ça marche sans rien casser en dev/Codespace aujourd'hui** : Symfony résout `%env(NOM)%` par ordre de priorité - une vraie variable d'environnement (ce que `docker-compose.yml` fournit toujours à `backend`/`backend-worker`/`mercure-publisher` aujourd'hui) l'emporte **toujours** sur le coffre. Le coffre ne prend donc effet que là où ces variables ne sont *pas* fournies autrement - c'est-à-dire uniquement en production une fois déployée (A3.8), jamais aujourd'hui. **Vérifié en direct** sur le Codespace : `env -u APP_SECRET -u JWT_PASSPHRASE -u MERCURE_JWT_SECRET php bin/console about --env=prod` (ces 3 variables explicitement absentes du process, pas juste vides - une différence réelle pour la résolution `env()` de Symfony) démarre proprement en `prod`, `Debug: false`, sans erreur - la seule façon dont ça peut fonctionner est que les 3 valeurs viennent bien du coffre chiffré.

**A3.4 (rate limiting général)** : `App\EventListener\ApiRateLimitListener`, un plafond de requêtes par minute sur **tout** `/api/*`, "configurable par rôle" (nouveaux limiteurs `api_anonymous`/`api_admin`/`api_internal_analyst`/`api_external_partner`, `config/packages/rate_limiter.yaml`) - distinct de `login_throttling` (A1.4, protège l'authentification elle-même) et de `ExportQuotaPolicy` (A2.3, un volume métier précis, pas le trafic général). Anonyme = clé par IP client (`trusted_proxies`/`trusted_headers` ajoutés à `framework.yaml` pour que `getClientIp()` reste correct derrière un futur reverse proxy réel, sans rien changer au comportement actuel où Apache reçoit directement le trafic) ; authentifié (JWT ou clé API) = clé par id utilisateur, plafond dépendant du rôle. Exclus explicitement : `/api/auth/login` (déjà protégé, plus strictement, par `login_throttling`), `/api/doc` (pas une cible d'abus réaliste), `/api/health` (sondé par Docker/un monitoring externe sur son propre rythme, ne doit jamais être bloqué).

**Piège réel rencontré et corrigé (2026-09-02)** : un plafond général appliqué à *chaque* requête `/api/*` casse la suite de tests fonctionnels si on le laisse actif en environnement `test` - une suite de ~180 tests dépasse en quelques secondes n'importe quel plafond par minute réaliste, et **aucun choix de stockage ne règle ça** (testé en direct : le stockage `cache.app` habituel du rate limiter, partagé et persistant entre process, fait fuiter l'état d'un test vers des tests sans rapport partageant la même IP anonyme "127.0.0.1" ou le même utilisateur de fixture - 8+ tests pré-existants et sans rapport se sont mis à échouer en 429 ; un stockage en mémoire `cache.adapter.array`, à l'inverse, casse les tests qui accumulent volontairement plusieurs requêtes dans une boucle, comme `testSixthFailedLoginAttemptIsThrottled` ou `testExportQuotaIsEnforcedByRole` - `WebTestCase` redémarre le noyau à chaque requête par défaut, donc un stockage qui ne survit pas au process ne voit jamais l'état accumulé). Solution retenue : le listener se désactive entièrement quand `%kernel.environment%` vaut `test` (nouveau paramètre injecté), et son mécanisme réel est testé en isolation (`tests/EventListener/ApiRateLimitListenerTest.php`, `TestCase` pur - construit le listener directement avec ses propres limiteurs en mémoire privés, jamais via le client HTTP/la config partagée). Suite complète re-vérifiée après coup : 0 régression (1 seule erreur, préexistante et sans rapport - signature de data provider dans `UserControllerTest`, jamais touché ici).

**Vérifié en direct sur le Codespace** (backend réel, pas juste les tests) : 30 requêtes anonymes vers `GET /api/analytics/hero-stats` → 200, la 31e → `429` avec `{"code":429,"message":"..."}` et un en-tête `Retry-After` réel (au passage, `App\EventListener\ApiExceptionListener` - A2.1 - ne recopiait aucun en-tête de l'exception d'origine vers la réponse JSON qu'il construit ; corrigé au même moment, sinon `Retry-After` aurait été silencieusement perdu) ; `/api/health` et `/api/doc` restent à 200 malgré le plafond anonyme épuisé, confirmant l'exclusion.

### A3.7 - Tests de charge & performance

Cible du cahier des charges 5.5 : **<500ms**. Outil : [k6](https://k6.io/), scripts et rapport complet dans [`loadtest/`](loadtest/README.md) - reproductible (`BASE_URL=http://localhost:8080 k6 run loadtest/baseline.js`), jamais exécuté contre le service Render de production (palier gratuit, vraie ressource).

**Résultat** : baseline (5 utilisateurs virtuels simultanés, sous la capacité machine) - `GET /api/funding` filtré p95 87ms, les 3 agrégats `/api/analytics/*` p95 31-36ms. **Tous largement sous la cible.** `EXPLAIN ANALYZE` confirme le SQL lui-même sous la milliseconde, et `FundingRepository` charge déjà pays/secteur/source en une seule requête (`addSelect`/`join`) - aucune requête N+1 trouvée.

**Montée en charge à 100 VUs simultanés** : p95 dépasse la cible (993ms-1.35s) - mais causé par la contention CPU de ce Codespace à 4 vCPU partagés avec toute la stack Volet B (Kafka/Zookeeper/Airflow/2 validateurs/MinIO, ~15 conteneurs actifs simultanément, vérifié via `docker stats`), pas par le code ou la base de données - un environnement de production dédié n'a pas cette contention. Détail complet dans `loadtest/README.md`.

**Indexation (migration `Version20260902133208`)** : ajout d'un index partiel `idx_funding_is_current` sur `funding.is_current`, filtré par les 3 agrégats analytics et `FundingController` sans condition supplémentaire sélective. Ajouté **de façon proactive, pas réactive** : à 1 080 lignes (100% `is_current = true` aujourd'hui), le planificateur PostgreSQL choisit toujours un `Seq Scan` (comportement correct - un balayage séquentiel est réellement plus rapide à 100% de sélectivité), mais l'historisation SCD2 du pipeline Volet B (point 36) fait grandir la queue de lignes non-courantes indépendamment du nombre de lignes courantes à chaque ré-exécution sur des données déjà vues - le planificateur commencera à exploiter cet index automatiquement, sans changement de code, dès que la sélectivité de `is_current` diminuera. Partagé le même faux positif Doctrine/DBAL déjà documenté pour `uniq_funding_dedup_key_current` (`Funding.php`) - confirmé en direct, comportement attendu.

### A3.8 - Mise en production (Render + Netlify)

**État réel (2026-09-02)** : les deux environnements de production existaient déjà (mis en place par l'utilisateur directement, hors de ce dépôt de code) - ce travail a consisté à **vérifier** qu'ils sont cohérents avec l'exigence du plan (« Environnement de production accessible en HTTPS via le nom de domaine »), pas à les créer de zéro.

- **Backend** : `https://nev-climate-data.onrender.com` - conteneur Docker (`docker/backend/Dockerfile`), HTTPS valide (certificat Google Trust Services, standard sur les sous-domaines `*.onrender.com`). `GET /api/health` → 200 vérifié en direct.
- **Frontend** : `https://nevclimatedata.netlify.app` - HTTPS valide. `frontend/_redirects` proxifie `/api/*` vers le backend Render ci-dessus ; CORS vérifié en direct (`Access-Control-Allow-Origin: https://nevclimatedata.netlify.app` renvoyé par le backend pour cette origine précise).
- **Nom de domaine** : sous-domaines gratuits Render/Netlify jugés suffisants pour l'instant (décision utilisateur explicite, 2026-09-02) - aucun nom de domaine personnalisé prévu à ce stade.

**Piège réel rencontré et corrigé en direct** : le build Netlify, désactivé depuis le 2026-09-01 (ressources limitées), a été réactivé pour cette vérification - mais la migration React de septembre a rendu l'ancien réglage (aucune commande de build, publication du dossier `frontend/` tel quel) obsolète : le site servait un HTML figé d'avant la migration (200 OK, HTTPS valide, aucune erreur visible - voir point d'attention #45), puis un premier essai de commande de build a échoué à cause d'un `Base directory` déjà réglé sur `frontend` (voir point d'attention #44). Corrigé en deux temps, chaque étape revérifiée en direct par un vrai déploiement avant de passer à la suivante : Build command = `npm install && npm run build`, Publish directory = `frontend` (inchangé).

**Vérifié en direct après correction, sur les deux environnements réels, pas seulement supposé fonctionner** : les 13 pages du site (`404.html` à `visualizations.html`) répondent 200 avec les bundles `assets/react/*.js` réellement construits par Vite (plus aucune trace de l'ancien HTML statique) ; les bundles JS eux-mêmes répondent 200 ; `GET /api/funding?limit=1` à travers le proxy Netlify → Render renvoie de vraies données ; la page 404 personnalisée s'affiche correctement.

### A3.11 - Refonte UI/UX institutionnelle (2026-09-03, hors plan officiel)

Demande explicite de l'utilisateur, avant l'interconnexion avec le Volet B : donner au frontend (fonctionnellement complet depuis A3.10) un niveau de crédibilité visuelle institutionnelle (référence citée : Our World in Data / World Bank Data / UNDP Data - principes UX étudiés, pas copiés), sans toucher au backend, sans nouvelle fonctionnalité métier, sans donnée fictive, et sans casser l'existant. Aucune tâche du plan d'implémentation ne couvre ce travail - traité comme un chantier autonome entre A3.10 et l'ouverture du Volet B.

**Design system** (`frontend/src/input.css`) : nouvelle palette sémantique `--nev-*` déclarée comme source de vérité dans `:root`, le bloc `@theme` existant (requis par Tailwind pour générer les classes utilitaires `bg-primary`, etc.) référence désormais ces variables via `var()` au lieu de répéter des valeurs hex - zéro renommage de classe, zéro régression sur les 13 pages. Fond global de-verdi (`#f3faf5` → `#f7f8f7`, neutre) : le vert de marque (`#16a34a`, inchangé) reste une identité forte via les boutons/liens/accents plutôt que comme couleur de fond de page. Nouveau token `--nev-danger` (comblant une vraie lacune : les bannières d'erreur reprenaient jusqu'ici la couleur "donnée de démonstration" par erreur, une confusion sémantique corrigée au passage). `prefers-reduced-motion` ajouté globalement (absent partout auparavant).

**Bibliothèque de composants** (nouveau dossier `frontend/src/react/components/ui/`, 12 primitives - `NevButton`, `NevCard`, `NevKpi`, `NevDataState`, `NevInput`, `NevSelect`, `NevBadge`, `NevAlert`, `NevTable`, `NevPagination`, `NevChartCard`, `NevConfirmDialog`) : remplace des styles Tailwind dupliqués sur chaque page (aucun composant partagé n'existait avant ce chantier, hormis `charts/ChartStateBox.jsx`, conservé et réutilisé tel quel). `NevConfirmDialog` remplace les deux seuls `window.confirm()` du code (révocation de clé API, suppression d'utilisateur).

**Pages touchées** : les 13 pages + `Navbar`/`Footer`/`MinimalHeader`/`AuthSlot`/`GlobalSearch`/`NotificationBell`. Contrat API (`GET /api/funding`, `/api/search`, `/api/analytics/*`, `/api/reports`, `/api/auth/*`, `/api/api-keys`, `/api/users`, `/api/notifications/*`) et logique métier (filtres, export CSV/XLSX asynchrone, recherche, auth JWT, Mercure) strictement inchangés - seuls le style et, sur `DataPage.jsx`, un résumé de filtres actifs en chips (ajout purement visuel, lit le state existant) ont changé. `frontend/assets/js/*.js` (10 fichiers legacy pré-migration React, confirmés sans aucune référence restante par `grep` avant suppression) supprimés en toute fin de chantier.

**Vrais bugs trouvés et corrigés pendant la vérification** (voir points d'attention #48-#50) : un en-tête de tableau `sticky` sans conteneur de défilement borné se collait au-dessus de la navbar au lieu de rester dans le tableau ; le zoom manuel de la carte "Financement par pays (Afrique)" (`CountryMap.jsx`) appliquait deux fois la même correction d'échelle jsVectorMap, décentrant complètement la vue (Amériques affichées, Afrique hors cadre) ; le conteneur de cette même carte, une fois le centrage corrigé, ne respectait pas le vrai ratio largeur/hauteur de l'Afrique (mesuré directement depuis les données de la bibliothèque, ~171:188) et rognait le nord et le sud du continent.

**Correction de contenu au passage** : le texte de la page `api-docs.html` (FR + EN) décrivait encore l'API comme "en fondations (Phase A1)" alors que recherche/financements/statistiques/rapports sont en production depuis longtemps - corrigé dans `assets/i18n/{fr,en}.json`. Le libellé `sr-only` de la recherche globale disait encore "fonctionnalité à venir" (recherche en production depuis A2.8/A2.9) - corrigé.

**Vérification** : aucun outil de navigateur/capture d'écran n'est disponible dans cet environnement d'exécution - la vérification s'est faite par `npm run build` (aucune suite de tests frontend n'existe dans ce repo, confirmé) répété à chaque étape sur le Codespace (jamais en local, à la demande explicite de l'utilisateur), par un serveur `vite dev` réel servant les 13 pages avec le vrai backend (base de données, Redis) derrière, et par les captures d'écran fournies au fil de l'eau par l'utilisateur - qui ont d'ailleurs révélé les trois bugs listés ci-dessus, invisibles depuis les seules vérifications serveur. **Non fait** : parcours visuel complet et systématique des 13 pages par l'utilisateur - recommandé avant de considérer ce chantier définitivement clos.

## CI/CD

Pipeline GitLab CI (`.gitlab-ci.yml`), deux étapes :

| Étape | Job | Déclenchement | Rôle |
|---|---|---|---|
| `test` | `phpunit` | Tout push, toute branche | Installe PHP 8.4 + extensions, lance la suite PHPUnit contre un service TimescaleDB éphémère |
| `build` | `build_and_push_image` | Uniquement `developp`, et seulement si `phpunit` a réussi | Construit l'image Docker backend, la publie sur le Container Registry GitLab (tags : SHA du commit + `developp`) |

**Important - ceci n'est pas un déploiement.** L'image est publiée dans le Container Registry du projet ; rien ne la récupère ni ne la fait tourner automatiquement quelque part. Le déploiement d'un environnement de production réel est la tâche A3.8, plus tard dans le plan.

### Suivre l'état d'un pipeline

`https://gitlab.com/nev-consulting-group/nev-climate-data/-/pipelines`

### Voir les images publiées

`https://gitlab.com/nev-consulting-group/nev-climate-data/-/packages` (section Container Registry)

### Runners

Aucun runner dédié n'est configuré pour ce projet - le pool de runners partagés gitlab.com (confirmé disponible : **Settings → CI/CD → Runners → Instance**) est utilisé.

### Récupérer une image publiée

```bash
docker pull registry.gitlab.com/nev-consulting-group/nev-climate-data/backend:developp
```

Détails de conception complets : voir [`docs/superpowers/specs/2026-08-24-a17-cicd-pipeline-design.md`](docs/superpowers/specs/2026-08-24-a17-cicd-pipeline-design.md).

## Sauvegardes de la base de données (A3.6)

Nouveau service `db-backup` (`docker/db-backup/`), pattern boucle `sleep` identique à `mercure-publisher` (A3.1) - ce projet n'a aucune infrastructure cron nulle part, une boucle dans son propre conteneur `restart: unless-stopped` est plus simple et tout aussi fiable à cette échelle.

- **`backup.sh`** : `pg_dump --no-owner --no-privileges` (compressé, `gzip`) toutes les `BACKUP_INTERVAL_SECONDS` (défaut 86400 = 1 jour), envoyé vers un bucket MinIO dédié **`nev-climate-data-backups`** - volontairement séparé du bucket `nev-climate-data` du data lake Volet B (`pipeline/common/minio_staging.py`) : une sauvegarde opérationnelle n'est pas une donnée du pipeline, et les séparer garantit qu'un bug de rétention ou ce script ne peuvent jamais toucher aux données réelles du pipeline, ni l'inverse. `--no-owner --no-privileges` : la sauvegarde doit pouvoir se restaurer dans n'importe quel rôle cible (une base de vérification jetable, un futur environnement) sans échouer sur des `ALTER OWNER`/`GRANT` référençant un rôle absent là-bas.
- **Rétention** : une seule commande `mc ilm add --expiry-days 30` (configurable, `BACKUP_RETENTION_DAYS`) posée une fois au démarrage - MinIO applique l'expiration lui-même côté serveur, aucune purge à coder/planifier à la main.
- **`restore.sh`** : manuel/à la demande uniquement, jamais automatique. `docker compose run --rm --entrypoint /usr/local/bin/restore.sh db-backup <fichier> [base-cible]`.

**Piège réel rencontré** : `docker compose run --rm db-backup /usr/local/bin/restore.sh ...` (sans `--entrypoint`) ne lance PAS `restore.sh` - l'image a `ENTRYPOINT ["/usr/local/bin/backup.sh"]` (A3.6), donc Docker exécute `backup.sh` en lui passant la commande demandée comme de simples arguments ignorés, et la boucle de sauvegarde infinie démarre à la place. `docker compose run` ne remplace jamais l'`ENTRYPOINT`, seulement le `CMD` - `--entrypoint` est obligatoire pour lancer autre chose que le point d'entrée par défaut d'une image.

**Restauration vérifiée en direct (2026-09-02), pas seulement supposée fonctionner** : sauvegarde réelle produite, base jetable `restore_check` créée, restaurée avec `restore.sh`, comptages de lignes comparés à la base réelle sur 4 tables (`funding`, `country`, `users`, `report`) - identiques des deux côtés (1080/54/4/6), base jetable supprimée ensuite. `pg_dump` émet un avertissement inoffensif sur des contraintes circulaires dans `continuous_agg` (une table interne de TimescaleDB - ce projet n'appelle `create_hypertable()` nulle part, TimescaleDB n'est utilisé ici que comme distribution Postgres compatible ; aucune donnée applicative n'est concernée par cet avertissement).

## Points d'attention (pièges déjà rencontrés - à ne pas réintroduire)

73 pièges réels rencontrés et corrigés pendant ce projet (A1.3 à aujourd'hui) - accès,
configuration, tests, données, déploiement. Liste complète et numérotée, déplacée dans le
runbook d'exploitation pour garder ce README centré sur la prise en main :
[`docs/exploitation/runbooks.md` → §12](docs/exploitation/runbooks.md#12-points-dattention-à-ne-jamais-oublier).

## État d'avancement

**Fait (Phase A1 - Fondations, ~13 tâches sur le plan) :**

| Tâche | Contenu | Statut |
|---|---|---|
| A1.1 | Environnement Docker Compose | ✅ Fait (Oumar) |
| A1.2 | Squelette API Symfony | ✅ Fait (Oumar) |
| A1.3 | Schéma TimescaleDB « pipeline-ready » (8 entités + enums, 2 migrations) | ✅ Fait |
| A1.4 | Authentification JWT (login/refresh/logout/me, rôles, anti-brute-force) | ✅ Fait |
| A1.5 | Gestion des clés API (génération, quotas, révocation) | ✅ Fait - application du quota (compteur d'usage) non encore implémentée, voir « Limites actuelles » |
| A1.6 | Scripts de seed/fixtures (jeu de données de démonstration) | ✅ Fait |
| A1.7 | Pipeline CI/CD (build, tests, publication d'image) | ✅ Fait - publie l'image sur le Container Registry, ne déploie pas (voir section CI/CD) |
| A1.8 | Recette Auth → API → Base de données | ✅ Fait - **Validé**, Phase A1 formellement recettée (voir [`docs/superpowers/specs/2026-08-25-a18-phase-a1-recette.md`](docs/superpowers/specs/2026-08-25-a18-phase-a1-recette.md)) ; un bug bloquant trouvé pendant la recette (Apache ne transmettait pas `Authorization` à PHP) a été corrigé et re-vérifié |

**Phase A1 close.**

**Fait (Phase A2 - en cours) :**

| Tâche | Contenu | Statut |
|---|---|---|
| A2.1 | `GET /api/funding` : filtres, pagination, accès public, CORS, erreurs JSON uniformes | ✅ Fait |
| A2.2 | `data.html` connecté aux vraies données (`GET /api/funding`) - plus aucune donnée simulée | ✅ Fait |
| A2.3 | Export CSV/Excel : génération synchrone et asynchrone (au-delà de 10 000 lignes, avec notification), quotas par rôle | ✅ Fait |
| A2.4 | Bouton d'export branché sur le module réel (retrait de l'ancienne alerte) | ✅ Fait |
| A2.5 | Endpoints d'agrégats analytiques (tendances de financement, répartition sectorielle, CO2), cache Redis TTL 15 min | ✅ Fait |
| A2.6 | Graphiques Chart.js connectés aux agrégats réels, états de chargement / « Donnée non disponible » | ✅ Fait |
| A2.7 | Endpoint des statistiques d'en-tête (compteurs Hero calculés dynamiquement, mis en cache) | ✅ Fait |
| A2.8 | Recherche globale insensible casse/accents (PostgreSQL `unaccent`), pays/sources/rapports | ✅ Fait |
| A2.9 | Barre de recherche du header branchée sur l'API, résultats groupés par catégorie | ✅ Fait |
| A2.10 | Module de notifications (backend + icône du header, statut lu/non lu, navigation directe vers la page concernée) | ✅ Fait |
| A2.11 | Internationalisation FR/EN : fichiers de traduction (`frontend/assets/i18n/`), sélecteur de langue, bascule dynamique sans rechargement | ✅ Fait |
| A2.12 | Menu mobile complet sous le breakpoint `lg` (recherche, notifications, connexion désormais accessibles depuis le menu hamburger) | ✅ Fait |
| A2.13 | Section Rapports : `GET /api/reports` (liste, filtres par type/pays, pagination) + `GET /api/reports/{id}/download` (téléchargement PDF comptabilisé), `reports.html` connecté aux vraies données | ✅ Fait |
| A2.14 | Recette Phase A2 (voir [`docs/superpowers/specs/2026-08-29-a214-phase-a2-recette.md`](docs/superpowers/specs/2026-08-29-a214-phase-a2-recette.md)) | ✅ Fait - toutes catégories vertes ; un bug réel trouvé et corrigé pendant la recette (extension PostgreSQL `unaccent` marquée migrée mais jamais créée, voir point 24 des « Points d'attention ») ; en attente de la signature formelle du Product Owner |

**Travaux supplémentaires réalisés hors plan officiel** (prérequis non couverts par une tâche dédiée) :

| Bloc | Contenu | Dans le plan ? |
|---|---|---|
| Préparation frontend | Fusion du HTML NEV existant avec le template Tailwind "Play" - 9 pages, thème vert, pipeline de build (voir [`frontend/README.md`](frontend/README.md)) | Non |
| Intégration frontend de l'authentification | Connexion réelle, session JWT/refresh, « Mon profil », « Mes clés API » (branche enfin A1.5 à une interface) | Non |

**Phase A2 (A2.1 à A2.14) fonctionnellement complète.**

**Fait (Phase A3 - en cours) :**

| Tâche | Contenu | Statut |
|---|---|---|
| A3.1 | Hub Mercure temps réel + republication périodique des KPIs (`mercure-publisher`) | ✅ Fait - voir « Migration React », section A3.1/A3.2 |
| A3.2 | Composants React abonnés au flux Mercure (`useMercureKpis()`) | ✅ Fait - a nécessité de migrer tout le frontend en React (Lots 0-5), voir « Migration React » |
| A3.3 | Secrets (APP_SECRET, JWT_PASSPHRASE, MERCURE_JWT_SECRET) externalisés vers le coffre-fort Symfony (`config/secrets/prod/`) | ✅ Fait - voir « A3.3 - Secrets externalisés & A3.4 - Rate limiting général » |
| A3.4 | Rate limiting général par rôle sur tout `/api/*` (`App\EventListener\ApiRateLimitListener`) | ✅ Fait - voir la même section |
| A3.5 | Tests automatisés sur les écarts réels trouvés (audit de couverture 186→193 tests) + couverture reportée en CI/CD | ✅ Fait - voir « Tests automatisés », section « Couverture de code (A3.5) » |
| A3.6 | Sauvegardes automatisées de la base (`db-backup`, MinIO, rétention 30j) + restauration testée et validée | ✅ Fait - voir « Sauvegardes de la base de données (A3.6) » |
| A3.7 | Tests de charge (k6, `loadtest/`) + index proactif sur `funding.is_current` | ✅ Fait - voir « A3.7 - Tests de charge & performance » |
| A3.8 | Mise en production HTTPS réelle (Render backend + Netlify frontend), vérifiée cohérente avec le plan | ✅ Fait - voir « A3.8 - Mise en production (Render + Netlify) » |
| A3.9 | Documentation technique et API à jour - 28 opérations Swagger, sécurité vérifiée endpoint par endpoint | ✅ Fait - voir « Documentation Swagger / OpenAPI » |
| A3.10 | Recette finale Volet A (Phases A1+A2+A3) | ✅ Proposée - [`docs/superpowers/specs/2026-09-02-a310-phase-a3-recette.md`](docs/superpowers/specs/2026-09-02-a310-phase-a3-recette.md), en attente de validation écrite du Product Owner |

**Travaux supplémentaires réalisés hors plan officiel (Phase A3)** :

| Bloc | Contenu | Dans le plan ? |
|---|---|---|
| A3.11 - Refonte UI/UX institutionnelle | Design system `--nev-*`, 12 nouveaux composants partagés (`components/ui/`), les 13 pages restylées, 3 vrais bugs trouvés et corrigés (voir points d'attention #48-#50) | Non |

**Phase A3 (A3.1 à A3.10) fonctionnellement complète - le Volet A dans son ensemble (Phases A1, A2, A3) est prêt à être clôturé**, sous réserve de la signature du rapport de recette A3.10. Le Volet B (pipeline de données réelles) a démarré en parallèle : voir la section « Pipeline (Volet B) » plus bas. Détail complet, échéances et responsables : `Plan_Implementation_NEV_Climate_Data.xlsx`, onglet « Plan d'implémentation ».

**Documentation de conception disponible** pour tout ce qui est fait jusqu'ici (décisions prises, alternatives écartées, justifications) :
- [`docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md`](docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md) + [`docs/superpowers/plans/2026-08-22-a13-timescaledb-schema.md`](docs/superpowers/plans/2026-08-22-a13-timescaledb-schema.md)
- [`docs/superpowers/specs/2026-08-24-a14-jwt-authentication-design.md`](docs/superpowers/specs/2026-08-24-a14-jwt-authentication-design.md) + [`docs/superpowers/plans/2026-08-24-a14-jwt-authentication.md`](docs/superpowers/plans/2026-08-24-a14-jwt-authentication.md)

**Fait (Phase B1 - Connecteurs sources officielles) :**

| Tâche | Contenu | Statut |
|---|---|---|
| B1.1 | Connecteur Banque Mondiale (World Bank Data API), collecte trimestrielle | ✅ Fait |
| B1.2 | Connecteur GCF (IATI Datastore), collecte trimestrielle | ✅ Fait |
| B1.3 | Connecteur BAD/AfDB (IATI Datastore), conversion XDR→USD réelle, collecte trimestrielle | ✅ Fait |
| B1.4 | Connecteur PNUE (API SDG ONU, émissions CO2), topic/table dédiés, collecte trimestrielle | ✅ Fait |
| B1.5 | Extracteur PDF OPEC Fund (Gemini), cache SHA-256, collecte trimestrielle | ✅ Fait |
| B1.6 | Processor de validation et normalisation | ✅ Fait - essentiel déjà livré avec B1.1-B1.5 ; un vrai manque de robustesse trouvé et corrigé (message malformé → quarantaine au lieu de planter le service) |
| B1.7 | Gestion des conflits entre sources et historisation | ✅ Fait - un vrai bug de lecture trouvé et corrigé (`isCurrent` jamais filtré côté API, dashboard gonflé d'environ 65%) |
| B1.8 | Isolation Kafka/MinIO et architecture Bronze/Silver/Gold vis-à-vis de CIMA | ✅ Fait - isolation vérifiée en direct ; Gold = TimescaleDB par design (pas d'export MinIO redondant) |
| B1.9 | Orchestration Airflow : DAGs robustes (retries), alerting email réel sur échec | ✅ Fait - vérifié en conditions réelles, confirmé par Serge |
| B1.10 | Recette Phase B1 (voir [`docs/superpowers/specs/2026-09-01-b110-phase-b1-recette.md`](docs/superpowers/specs/2026-09-01-b110-phase-b1-recette.md)) | ✅ Fait - toutes catégories vertes ; un écart mineur non bloquant trouvé et documenté ; en attente de la signature formelle du Product Owner |

**Travaux supplémentaires réalisés hors plan officiel (Phase B1)** :

| Bloc | Contenu | Dans le plan ? |
|---|---|---|
| Refactoring multi-tâches des 5 DAGs | Chaque DAG passe d'une tâche unique à 3 tâches réellement reliées (`extraire >> transformer >> publier`), transit par MinIO Bronze/Silver | Non |
| Correction du double comptage Funding | Bug réel de production trouvé en creusant B1.7 : idempotence par projet (`funding_project_contribution`), données corrompues reconstruites | Non |
| Fréquence de collecte unifiée trimestrielle | Décision de gouvernance de Serge (2026-09-01) : GCF (était mensuel) et PNUE/OPEC Fund (étaient annuels) alignés sur la même cadence que les autres connecteurs | Non |

**Phase B1 (B1.1 à B1.10) fonctionnellement complète.** Prochaine étape : Phase B2 (connecteur
GreenAccess). Détail complet : `Plan_Implementation_NEV_Climate_Data.xlsx`.

**Fait (Phase B3 - anticipée avant B2, à la demande explicite de l'utilisateur pour vérifier que tout fonctionne avec des données réelles) :**

| Tâche | Contenu | Statut |
|---|---|---|
| B3.1 | Exposer les agrégats réels via les endpoints existants, sans changement de contrat | ✅ Fait - trois vrais bugs trouvés et corrigés en vérifiant avec des données réelles (voir points d'attention #52-#54) ; les 5 connecteurs Volet B tournés pour de vrai pour la première fois dans cet environnement (~5 149 lignes `funding` réelles World Bank/GCF/AfDB/OPEC Fund PDF, hors démo) |
| B3.2 | Connecter la diffusion des KPIs réels (et GreenAccess) au hub Mercure | ✅ Fait - `AnalyticsService::getCo2Reduction()` rebranché sur la vraie table `Emission` (B1.4), variation CO2 année sur année sur l'intersection des pays communs ; `co2ReductionMt` ajouté au payload Mercure ; tuile CO2 du frontend rebranchée + badge « Direct ». Le flux financement était déjà réel (mêmes méthodes que B3.1 a corrigées). GreenAccess explicitement hors périmètre (Phase B2 « Bloqué », aucune donnée). Voir « B3.2 - Diffusion des KPIs réels via Mercure » plus bas et [`docs/superpowers/specs/2026-09-07-b32-mercure-realtime-kpis-design.md`](docs/superpowers/specs/2026-09-07-b32-mercure-realtime-kpis-design.md) |
| B3.3 | Basculer progressivement le frontend des données de démonstration vers les données réelles | ✅ Fait - filtre `validation_status = 'validated'` systématique côté lecture (`FundingRepository`, `EmissionRepository`, `AnalyticsService::getHeroStats()`), jumeau du filtre `isCurrent` de B1.7. Bascule unique (les 5 connecteurs avaient déjà tourné) plutôt que progressive par source - la démo n'est pas taguée par source réelle. Rapports/notifications hors périmètre (aucune source réelle n'existe pour eux). Voir « B3.3 - Bascule démo → réel » plus bas et [`docs/superpowers/specs/2026-09-08-b33-demo-to-real-switch-design.md`](docs/superpowers/specs/2026-09-08-b33-demo-to-real-switch-design.md) |
| B3.4 | Comparer les valeurs affichées aux requêtes SQL de contrôle | ✅ Fait - 5 agrégats analytiques comparés à leur requête SQL directe, correspondance exacte sur tous. Un vrai défaut latent trouvé et corrigé : 12 lignes `emission` (Soudan du Sud, 2000-2011) avec `value_mt = NaN` (l'API SDG ONU renvoie littéralement "NaN" pour certaines années, jamais filtré avant B3.4) - aurait cassé silencieusement `getCo2Reduction()` dès que la fenêtre des 2 années les plus récentes les aurait incluses. Voir [`docs/superpowers/specs/2026-09-08-b34-b35-recette-controle-qualite.md`](docs/superpowers/specs/2026-09-08-b34-b35-recette-controle-qualite.md) |
| B3.5 | Vérifier l'idempotence de chaque DAG (rejeu sans doublon ni perte) | ✅ Fait - les 5 DAGs redéclenchés sur un état déjà peuplé : 0 doublon, 0 perte sur toutes les sources. Seul écart observé (AfDB, -0,00006%) confirmé être une vraie révision de la source IATI absorbée en delta par `funding_project_contribution`, jamais une addition aveugle. OPEC Fund confirme le cache SHA-256 (0 message republié). Voir même document que B3.4 |
| B3.6 | Réaliser la recette finale du Volet B et valider la mise en production | ✅ Proposée - [`docs/superpowers/specs/2026-09-08-b36-recette-finale-volet-b.md`](docs/superpowers/specs/2026-09-08-b36-recette-finale-volet-b.md), en attente de validation écrite du Product Owner. Volet B (B1+B3) recetté ; Phase B2 (GreenAccess) explicitement hors périmètre, toujours « Bloqué ». Cohérence dev/production vérifiée (mêmes chiffres réels des deux côtés). 2 réserves non bloquantes : nettoyage SQL manuel des 12 lignes `emission` corrompues (dev+prod), et rejeu recommandé de 2 suites de tests pipeline non exécutées cette session (lenteur d'environnement) |

**Travaux supplémentaires réalisés hors plan officiel (Phase B3)** :

| Bloc | Contenu | Dans le plan ? |
|---|---|---|
| Conversion réelle en devise locale (World Bank/GCF/OPEC Fund) | `pipeline/common/fx_rates.py` (taux USD→devise en direct, `open.er-api.com`, même API que la conversion XDR→USD d'AfDB/B1.3), branché dans `funding_validator.py` pour les 3 sources qui ne portent pas nativement de montant non-USD ; jamais la table illustrative `AfricanCurrencies` (réservée au jeu de démonstration). `pipeline/scripts/backfill_local_currency.py` a rattrapé les ~5 149 lignes déjà en base. Couverture vérifiée à 100% après coup sur toutes les sources concernées | Non |
| Correction du filtre de plage de dates (`periodStart`/`periodEnd`) | Bug réel trouvé en vérifiant B3.1 avec des données réelles (voir point d'attention #53) | Non |
| Reconstruction de `funding-validator`/`emission-validator` | Image Docker figée avant l'ajout du dispatch par source, plantait en boucle et bloquait silencieusement tout le flux Kafka (voir point d'attention #52) | Non |

**Fait (Tâches transverses - T) :**

| Tâche | Contenu | Statut |
|---|---|---|
| T.1 | Documentation technique à jour (README, schémas, runbooks) | ✅ Fait - [`docs/exploitation/runbooks.md`](docs/exploitation/runbooks.md) (procédures opérationnelles : démarrage/arrêt de la pile, reprise après `git pull`, tests, déclenchement des collectes, incidents rapports/NaN, sauvegarde/restauration, déploiement, Codespace, cohérence dev↔prod) ; [`docs/superpowers/HANDOFF.md`](docs/superpowers/HANDOFF.md) rafraîchi (était bloqué au commit `0e2aa3d`) ; le présent tableau d'avancement tenu à jour tâche par tâche |
| T.2 | Suivi et optimisation des coûts | ✅ Fait - [`docs/exploitation/suivi-couts.md`](docs/exploitation/suivi-couts.md) : tableau de suivi mensuel (13 postes), historique, 7 risques de bascule vers un coût non nul (dont R1 : expiration à 30 j de la base PostgreSQL gratuite Render), leviers d'optimisation déjà en place, procédure de relevé mensuel. Configuration actuelle : **coût monétaire ~nul**, le coût réel est un coût de risque (plafonds, offres gratuites) |
| T.3 | Former l'équipe interne (support + session(s)) | ✅ Support livré - [`docs/exploitation/formation-equipe.md`](docs/exploitation/formation-equipe.md) : objectifs pédagogiques, 3 parcours selon le public, modules (nature des données, lecture des tableaux de bord, pipeline en pratique, gestion d'incident), exercices pratiques, glossaire, journal des sessions. Dépendait de B3.6 (satisfaite). **Réserve : les sessions elles-mêmes restent à animer et à tracer** (§10 du support) |

**Tâches transverses : documentation et support livrés.** Restent à réaliser : les sessions de formation (T.3) et le relevé mensuel des coûts (T.2, récurrent).

**Fait (Palier C1 - « Où est l'argent ? », 8 tâches sur le plan, voir [`docs/superpowers/plans/2026-09-10-c1-c3-implementation-plan.md`](docs/superpowers/plans/2026-09-10-c1-c3-implementation-plan.md)) :**

| Tâche | Contenu | Statut |
|---|---|---|
| C1.1 | Dashboard consolidé (`/dashboard`) + `GET /api/analytics/data-freshness` | ✅ Fait (P0) |
| C1.2 | Sélecteur de couche sur `CountryMap` (secteur/instrument/année/type) | ✅ Fait (P0) |
| C1.3 | Recherche à facettes (pays/secteur/instrument/année/montant) | ✅ Fait (P1) |
| C1.4 | Fiches institutions (`GET /api/institutions/{sourceId}` + `InstitutionProfilePage.jsx`) | ✅ Fait (P0) |
| C1.5 | Sankey des flux bailleur → pays → secteur (`GET /api/analytics/funding-flows`, top-N + « Autres ») | ✅ Fait (P1) - un vrai bug de comptage trouvé par les tests, voir point d'attention #59 |
| C1.6 | Ventilation généralisée des tendances (`?groupBy=`) + sélecteur front + moyenne mobile 3 ans | ✅ Fait (P0) |
| C1.7 | Explorateur d'instruments (`instrument-distribution` + `InstrumentsPage.jsx`) | ✅ Fait (P1) |
| C1.8 | Radar d'opportunités (`GET /api/opportunities` + `OpportunityRadarPage.jsx`) | ✅ Fait (P2 - saisie manuelle, pas de connecteur automatique, assumé dès le plan) |

**Palier C1 clos** (CI GitLab verte, pipeline #161, couverture 84,3%). Audit de conformité au
plan réalisé le 2026-09-22 (méthode : existence de code, pas un nouveau test fonctionnel complet
- voir la limite méthodologique du document) :
[`docs/superpowers/specs/2026-09-22-c1-recette.md`](docs/superpowers/specs/2026-09-22-c1-recette.md).
Fondations du palier C2 (F1-F7, §4 du plan) déjà quasi entièrement en place au démarrage de C2 : F1 (`FundingInstrument`), F2 (entité `Score`), F4 (`Project`/`Opportunity`), F5 (service `ai/` FastAPI + RAG pgvector), F6 (`ai/ingest.py`), F7 (`NdcTarget`) existent tous ; seul F3 (`ScoreService` + registre d'indicateurs, nécessaire à partir de C2.4) reste à écrire.

**Fait (Palier C2 - « Où l'argent devrait-il aller ? ») :**

| Tâche | Contenu | Statut |
|---|---|---|
| C2.1 | `POST /api/ai/ask` (proxy vers `ai/` RAG) + `AiCopilotPanel.jsx` (tiroir de chat sur toutes les pages, via `Footer.jsx`) | ✅ Fait |
| C2.2 | Score de correspondance projet↔opportunité (`GET /api/projects/{id}/matches`, pondération secteur/pays/instrument/montant/fenêtre) | ✅ Fait - encart "Financements pertinents" intégré au détail de carte du Kanban (C2.3), pas de fiche projet dédiée séparée |
| C2.3 | Tableau Kanban du pipeline projet (`GET/PATCH /api/projects`, `ProjectPipelinePage.jsx`, glisser-déposer HTML5 natif) | ✅ Fait |
| C2.4 | Score de bancabilité (`GET /api/projects/{id}/scores/bankability`, 5 indicateurs dont le risque pays de C2.8) | ✅ Fait - jauge + détail des composantes sur la fiche projet (modale Kanban) |
| C2.5 | Calculateur d'impact (`POST /api/impact/estimate`, table `impact_factor`) | ✅ Fait - page dédiée `impact-calculator.html` (accessible depuis `visualizations.html` et la fiche projet, pré-remplissable), `GET /api/sectors` ajouté pour peupler le sélecteur |
| C2.6 | Score d'alignement CDN (`GET /api/countries/{iso}/scores/ndc-alignment`) | ✅ Fait - page dédiée `country-profile.html` (accessible depuis `visualizations.html`) |
| C2.7 | Classification taxonomique (`POST /api/classify`, few-shot Gemini + cache par hash côté `ai/`, `PATCH /api/projects/{id}/classify` pour persister sur un projet) | ✅ Fait - badge de classe + bouton de classification sur la fiche projet, filtre par classe sur le Kanban |
| C2.8 | Score de risque d'investissement (`GET /api/countries/{iso}/scores/investment-risk`, `GET /api/projects/{id}/scores/investment-risk` - risque pays x secteur x projet) | ✅ Fait - jauges sur la fiche projet et `country-profile.html` |

**Palier C2 fonctionnellement complet, backend ET frontend** (8/8 tâches) - le volet frontend de C2.4/C2.5/C2.6/C2.7/C2.8, prévu par le plan initial mais laissé de côté lors du premier passage sur C2, a été rattrapé le 2026-09-22. Reste en dette assumée : le traitement par lot des lignes `funding` réelles via un DAG dédié à la classification (explicitement hors périmètre de ce sprint, voir le plan §7) - la classification C2.7 reste déclenchée projet par projet, jamais en masse.

**Écart trouvé et corrigé le 2026-09-22 en auditant C2 contre le plan original** : C2.2 ne
couvrait que le sens « pour un projet, les opportunités les plus pertinentes »
(`GET /api/projects/{id}/matches`) - le plan demande aussi le sens inverse (« pour une
opportunité, les projets les plus pertinents »), qui n'existait pas. Ajouté :
`GET /api/opportunities/{id}/candidates` (`ProjectMatchingService::candidatesForOpportunity()`,
même score pondéré que le sens direct), vérifié en direct et couvert par 3 nouveaux tests. Voir
[`docs/superpowers/specs/2026-09-22-c2-recette.md`](docs/superpowers/specs/2026-09-22-c2-recette.md).

**Fait (Palier C3 - « L'argent atteint-il la bonne cible ? ») :**

| Tâche | Contenu | Statut |
|---|---|---|
| C3.1 | Détecteur d'écarts (`GET /api/analytics/gaps?by=`, besoin CDN déclaré - reçu, seuils de sévérité) | ✅ Fait - `gaps.html` (tableau trié ; pas de carte choroplèthe, voir écart ci-dessous) |
| C3.2 | Suivi du financement local (`GET /api/analytics/local-finance`, proxy instrument+montant) | ✅ Fait - indicateur « part locale estimée » sur `country-profile.html`, ajouté le 2026-09-22 (initialement manquant, corrigé le jour même) |
| C3.3 | Score d'équité (`GET /api/analytics/equity`, classement + indice de Gini) | ✅ Fait - `equity.html` |
| C3.4 | Simulateur de finance mixte (`POST /api/blended/simulate`) | ✅ Fait - `blended-finance.html` |
| C3.5 | Détection d'écoblanchiment (`POST /api/greenwashing/check`, Gemini + cache) | ✅ Fait - pastille + panneau explicatif sur la fiche projet |
| C3.6 | Score de transparence (`GET /api/institutions/{id}/scores/transparency`, `GET /api/analytics/transparency`) | ✅ Fait - jauge sur `institution-profile.html` + `transparency.html` (classement) |
| C3.7 | Moteur de recommandations (`GET /api/recommendations`, agrégation transparente C3.1+C3.3+C2.6+C2.8) | ✅ Fait - `recommendations.html` |
| C3.8 | Alertes intelligentes (`GET/POST/PATCH /api/alert-rules`, commande `app:alerts:evaluate`) | ✅ Fait - réglages dans `account-profile.html`, badge « Alerte » dans `notifications.html` |

**Palier C3 : 8/8 tâches backend livrées et vérifiées en direct ; 38 tests automatisés
(services + commande) écrits et verts ; frontend construit pour 8/8 tâches** (C3.2 initialement
oublié, corrigé le même jour). Recette complète, écarts au plan documentés en détail et réserves
proposées :
[`docs/superpowers/specs/2026-09-22-c3-recette.md`](docs/superpowers/specs/2026-09-22-c3-recette.md).

## Pipeline (Volet B)

Premier connecteur du Volet B (données réelles) : collecte trimestrielle des financements
climat de la Banque Mondiale. Architecture complète et décisions de conception :
[`docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`](docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md)
(fondation partagée) et
[`docs/superpowers/specs/2026-08-26-b11-world-bank-connector-design.md`](docs/superpowers/specs/2026-08-26-b11-world-bank-connector-design.md)
(ce connecteur). Plan d'implémentation détaillé (10 tâches, avec les bugs réels trouvés et
corrigés en cours de route) :
[`docs/superpowers/plans/2026-08-26-b11-world-bank-connector.md`](docs/superpowers/plans/2026-08-26-b11-world-bank-connector.md).

### Services

`docker compose up -d` démarre désormais aussi `zookeeper`, `kafka`, `postgres-airflow`,
`airflow`, `minio`, `kafka-ui` et `funding-validator`, en plus des services existants.

- Interface Airflow : `http://localhost:8081` (utilisateur `admin` ; mot de passe généré au
  premier démarrage et écrit dans
  `docker compose exec airflow cat /opt/airflow/standalone_admin_password.txt` - **pas** dans
  les logs du conteneur, malgré ce qu'indique la documentation Airflow standalone habituelle)
- Console MinIO : `http://localhost:9001` (identifiants : `MINIO_ROOT_USER`/`MINIO_ROOT_PASSWORD`
  du `.env` racine)
- Kafka UI (navigateur visuel des topics/messages, ajouté pour l'observabilité locale) :
  `http://localhost:8083`

### Déclencher la collecte manuellement

```bash
docker compose exec airflow airflow dags trigger collecte_worldbank
```

### Lancer les tests du pipeline

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/ -v -m "not live"
```

Le test marqué `live` (`pipeline/tests/test_world_bank_collector_live.py`) appelle la vraie
API Banque Mondiale - à exécuter séparément (`-m live`) plutôt qu'en routine, pour ne pas rendre
la suite dépendante du réseau.

Exception depuis le refactoring multi-tâches des DAGs (voir ci-dessous) : les fichiers
`pipeline/tests/test_dag_*_tasks.py` importent directement les modules de DAG, qui importent
`airflow` - ils ne peuvent donc pas tourner dans l'image `funding-validator` (qui n'a pas Airflow
installé, et n'en a pas besoin pour son propre rôle de consommateur Kafka). Ils s'exécutent dans
le conteneur `airflow` lui-même :

```bash
docker compose exec airflow pytest pipeline/tests/test_dag_worldbank_tasks.py -v
```

`pytest` a été ajouté à `_PIP_ADDITIONAL_REQUIREMENTS` du service `airflow` dans
`docker-compose.yml` pour cette raison (il n'y était pas nécessaire avant, les DAGs n'ayant
jamais eu leur propre suite de tests jusqu'ici).

### Connecteur GCF (B1.2)

Deuxième connecteur du Volet B : collecte trimestrielle (fréquence unifiée pour tous les
connecteurs le 2026-09-01 - était mensuelle à l'origine) des financements du Fonds Vert pour le
Climat, via l'API IATI Datastore (pas l'API/dashboard propre du GCF, injoignable au moment de
la conception - voir la spec). Décisions de conception complètes :
[`docs/superpowers/specs/2026-08-28-b12-gcf-connector-design.md`](docs/superpowers/specs/2026-08-28-b12-gcf-connector-design.md).

Nécessite `IATI_API_KEY` dans le `.env` racine (clé gratuite, inscription sur
`developer.iatistandard.org` → Subscriptions → "Exploratory").

```bash
docker compose exec airflow airflow dags trigger collecte_gcf
```

Réutilise l'infrastructure et le topic `nev.funding.raw` déjà provisionnés par B1.1 - aucun
nouveau service, aucun nouveau topic.

### Connecteur BAD (B1.3)

Troisième connecteur du Volet B : collecte trimestrielle des financements de la Banque
Africaine de Développement (Groupe BAD), via la même API IATI Datastore que B1.2. Premier
connecteur à effectuer une vraie conversion de devise (XDR→USD, la BCE ne pouvant
structurellement pas servir cette devise - voir la spec) et à peupler pour de vrai les colonnes
`originalAmount`/`originalCurrency`/`exchangeRate` de `Funding`, réservées depuis A1.3.
Décisions de conception complètes :
[`docs/superpowers/specs/2026-08-28-b13-afdb-connector-design.md`](docs/superpowers/specs/2026-08-28-b13-afdb-connector-design.md).

```bash
docker compose exec airflow airflow dags trigger collecte_afdb
```

Réutilise `IATI_API_KEY` (déjà configuré depuis B1.2) et l'infrastructure existante - aucun
nouveau service, aucun nouveau topic. Contrairement à B1.2, ce connecteur pagine tout le
portefeuille (~5600 activités) au lieu d'une seule requête - voir le point d'attention sur la
limite de débit de l'offre gratuite IATI ci-dessous.

### Connecteur PNUE (B1.4)

Quatrième connecteur du Volet B : collecte trimestrielle (fréquence unifiée pour tous les
connecteurs le 2026-09-01 - était annuelle à l'origine) des émissions de CO2 par pays, via l'API SDG
des Nations Unies (indicateur 9.4.1, série `EN_ATM_CO2`) - **pas** une API PNUE native : une
recherche approfondie (12+ vérifications réelles) a confirmé qu'aucune API PNUE vivante et
alimentée avec une granularité par pays n'existe (API historique de 2016 morte, portail SDG
brandé PNUE vide, WESR sans API publique documentée, rapports phares du PNUE agrégés au niveau
G20 sans donnée pour la plupart des pays NEV). Les données réelles utilisées sont attribuées à
l'IEA - voir la spec pour le détail complet de cette recherche et de la décision.

Premier connecteur du Volet B à introduire ses propres topics Kafka dédiés
(`nev.emissions.raw`/`.valides`/`.rejets`, distincts de `nev.funding.*`) et sa propre table
(`emission`, distincte de `funding`) - le roadmap demandait explicitement un "topic Kafka dédié"
pour B1.4, et le domaine de données (impact environnemental) est différent du financement.
Historisation SCD2 comme `Funding`, mais avec une sémantique **remplacement, pas addition** : un
second message pour la même clé (source, pays, année) remplace la valeur courante au lieu de s'y
additionner - une statistique nationale annuelle est une estimation révisée, pas un flux de
transactions cumulatives.

Décisions de conception complètes :
[`docs/superpowers/specs/2026-08-29-b14-pnue-connector-design.md`](docs/superpowers/specs/2026-08-29-b14-pnue-connector-design.md).

```bash
docker compose exec airflow airflow dags trigger collecte_pnue
```

Aucune clé API requise (API publique). Vérifié en direct : 840 lignes réelles couvrant 35 des 54
pays NEV ; les 19 pays restants n'ont aucune donnée pour cet indicateur dans la source elle-même
(`totalElements: 0`, confirmé en direct pour le Burkina Faso par exemple) - une vraie lacune de
couverture de la source, pas un bug. Périmètre : collecte uniquement -
`AnalyticsService::getCo2Reduction()` (endpoint `/api/analytics/co2-reduction`) reste
volontairement non rebranché sur cette nouvelle table dans le cadre de B1.4.

### Extracteur PDF - OPEC Fund (B1.5)

Cinquième connecteur du Volet B, et premier à extraire des données structurées directement d'un
rapport PDF via une IA (Gemini), plutôt que via une API. Cible réelle : le rapport OPEC Fund
Climate Finance Report 2024, Annexe 2 (tableau de 111 projets réels, 2018-2023) - choisi après
avoir écarté trois autres candidats réels vérifiés en direct (rapport annuel GCF sans donnée par
projet, AFD qui a en fait sa propre API, CCDR Sénégal trop agrégé). Décisions de conception
complètes : [`docs/superpowers/specs/2026-08-29-b15-pdf-extractor-design.md`](docs/superpowers/specs/2026-08-29-b15-pdf-extractor-design.md).

```bash
docker compose exec airflow airflow dags trigger extraction_pdf
```

Nécessite `GEMINI_API_KEY` dans le `.env` racine (clé gratuite, palier "Free tier" sans carte
bancaire, `aistudio.google.com` → "Get API key"). Réutilise le topic `nev.funding.raw` et
`funding_validator.py` existants (contrairement à B1.4, ce n'est pas un nouveau domaine de
données) - aucun nouveau topic, aucun nouveau service de validation. Première utilisation réelle
de MinIO dans ce projet (provisionné depuis B1.1, resté vide jusqu'ici) : le PDF brut est stocké
dans le bucket `nev-climate-data`, préfixe `bronze/opec-fund-climate-finance-2024/`. Le cache par
hash SHA-256 (table `processed_document`) garantit qu'un même document n'est jamais ré-extrait
deux fois - vérifié en direct : un second déclenchement sur le même document a publié `0`
message en ~2 minutes (juste le téléchargement + hash), contre ~26 minutes pour la première
extraction réelle.

Vérifié en direct : 111 lignes réelles extraites intégralement et exactement (aucune valeur
inventée), 145 messages publiés vers `nev.funding.raw`, 48 lignes `Funding` réellement acceptées
après validation (le reste en quarantaine pour cause de secteur non mappable - comportement
attendu, voir la spec). Deux vrais bugs trouvés et corrigés pendant cette vérification bout-en-bout
(voir les points d'attention 30 et 32 ci-dessous) : un budget de délai HTTP trop juste et une
boucle de nouvelle tentative qui ne capturait pas les vraies coupures réseau.

### Refactoring multi-tâches des 5 DAGs (2026-08-31)

Chacun des 5 DAGs ci-dessus (B1.1 à B1.5) exécutait initialement toute sa logique - récupération,
transformation, publication - dans une seule tâche `PythonOperator`, ce qui n'affichait aucune
liaison de dépendance dans la vue "Graph" d'Airflow. Suite à une demande de Serge après relecture
des graphes réels, chaque DAG a été découpé en 3 tâches réellement reliées : `extraire >>
transformer >> publier`. Décisions de conception complètes :
[`docs/superpowers/specs/2026-08-31-airflow-multi-task-dags-design.md`](docs/superpowers/specs/2026-08-31-airflow-multi-task-dags-design.md).
Plan d'implémentation détaillé :
[`docs/superpowers/plans/2026-08-31-airflow-multi-task-dags.md`](docs/superpowers/plans/2026-08-31-airflow-multi-task-dags.md).

Les données intermédiaires transitent exclusivement par MinIO (préfixes `bronze/` puis `silver/`,
distincts de ceux de B1.5) - seul un chemin d'objet (texte court) circule en XCom entre les
tâches, jamais les enregistrements bruts eux-mêmes (AfDB seul en récupère plus de 5000 par
exécution, un volume qu'Airflow lui-même déconseille de faire transiter par XCom). Nouveau module
partagé `pipeline/common/minio_staging.py`, désormais réutilisé aussi par `pdf_extraction.py`
(B1.5). `extraction_pdf` gère son court-circuit de cache via un simple indicateur `cache_hit`
propagé en XCom entre les 3 tâches, sans branchement Airflow dynamique - chaque tâche suivante ne
fait rien si l'indicateur est vrai.

Aucun changement de résultat final : mêmes topics Kafka, mêmes clés de payload, même
dédoublonnage. `funding_validator.py`/`emission_validator.py` restent des services Kafka
permanents, hors Airflow, inchangés - intégrer la validation dans Airflow était une option
explicitement écartée par Serge.

Vérifié en direct : les 5 DAGs déclenchés réellement affichent chacun 3 tâches `success` reliées
dans la vue Graph (`extraire → transformer → publier`) - `collecte_worldbank` : 4427 messages
publiés ; `collecte_gcf` : 999 ; `collecte_afdb` : 3615 ; `collecte_pnue` : 840 ;
`extraction_pdf` : cache hit sur le document déjà traité depuis la vérification B1.5, `0` message
publié comme attendu - le court-circuit de cache fonctionne bout-en-bout, pas seulement dans les
tests mockés.

### Processor de validation et normalisation (B1.6)

B1.6 demande un "processor de validation et normalisation (devise pivot, upsert, valeurs
manquantes)" appliquant les règles de gouvernance de la section 6.4 du cahier des charges.
Vérification du code réel avant tout nouveau développement : l'essentiel de ce livrable
existe déjà, construit progressivement avec B1.1-B1.5 plutôt que comme tâche isolée -
`funding-validator`/`emission-validator` sont deux services Kafka permanents qui écrivent
directement dans TimescaleDB depuis B1.1, avec déduplication, upsert et historisation SCD2
réels (voir `upsert_funding()`/`upsert_emission()`). La conversion en devise pivot est
satisfaite en résultat (tout est en USD) mais par un mécanisme différent de celui prévu à
l'origine (taux BCE centralisés dans le processor) : aucun connecteur n'a eu besoin de cette
conversion centralisée en pratique - AfDB, seul cas réel de devise étrangère (XDR), convertit
dans son propre collecteur via `open.er-api.com`, la BCE ne cotant pas le XDR (déviation déjà
documentée en B1.3). "Absence ≠ zéro" est garanti au niveau des collecteurs, qui ne publient
jamais de message pour une donnée manquante. Décisions complètes et preuves détaillées :
[`docs/superpowers/specs/2026-08-31-b16-validation-processor-closure-design.md`](docs/superpowers/specs/2026-08-31-b16-validation-processor-closure-design.md).

Un vrai manque de robustesse a été trouvé et corrigé : `run()` n'entourait l'appel à
`process_message()` d'aucun `try`/`except` dans les deux processors - un message malformé
aurait fait planter tout le service permanent au lieu d'être mis en quarantaine comme tout
autre rejet. Corrigé : une exception inattendue publie désormais le message sur le topic
`.rejets` correspondant avec `rejection_reason: "processing_error:<NomException>"`, et le
service continue de consommer les messages suivants.

### Correction du double comptage Funding (2026-08-31)

Un vrai bug de production a été trouvé en creusant B1.7 (gestion des conflits entre sources) :
chaque DAG de collecte re-publie l'intégralité de son portefeuille à chaque exécution, et
`funding_validator.py` additionnait aveuglément chaque message reçu sans vérifier si le projet
avait déjà contribué lors d'un run précédent - gonflant indéfiniment les totaux réels à chaque
nouveau déclenchement (Sénégal/Agriculture/1989 : 16,1M → 128,8M après 8 déclenchements).
Décisions complètes et preuves détaillées :
[`docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md`](docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md).

Corrigé par une nouvelle table `funding_project_contribution` qui suit la dernière contribution
connue de chaque projet et applique un vrai delta (zéro si republié à l'identique, la différence
réelle si le montant a changé) au lieu de sommer aveuglément. Les données déjà corrompues des 3
sources concernées (Banque Mondiale, GCF, BAD - 37 073 lignes) ont été supprimées et reconstruites
à partir d'un seul run propre par connecteur - OPEC Fund PDF (protégé par son cache) et PNUE
(sémantique remplacement, jamais affecté) n'ont pas eu besoin de correction. Vérifié en direct
après reconstruction : Sénégal/Agriculture/1989 affiche exactement 16 100 000 (la valeur d'un seul
portefeuille), et la somme totale des lignes `Funding` courantes de la Banque Mondiale correspond
exactement à la somme des contributions individuelles suivies par projet
(268 138 642 869,00 des deux côtés).

### Isolation Kafka/MinIO et architecture Bronze/Silver/Gold (B1.8, 2026-08-31)

Vérifié en direct plutôt que supposé : l'isolation NEV/CIMA (cahier des charges 6.5) est déjà
réelle et complète - 6 topics Kafka, tous préfixés `nev.` (`nev.funding.*`, `nev.emissions.*`),
sur un broker dédié à ce projet ; un seul bucket MinIO (`nev-climate-data`) sur une instance
dédiée, ni l'un ni l'autre jamais connectés à l'infrastructure Observatoire CIMA. Bronze et Silver
sont désormais réellement écrits (B1.5 pour le stockage du PDF source, le refactoring multi-tâches
des 5 DAGs pour le transit bronze/silver entre `extraire` et `transformer`) - ce qui n'était pas
le cas quand B1.8 a été rédigé dans le roadmap (MinIO provisionné mais vide).

**Gold n'est délibérément pas un préfixe MinIO** : la spec d'architecture d'origine annote déjà
explicitement `TimescaleDB (Gold)` dans le diagramme du flux GreenAccess (B2) - TimescaleDB
(`funding`/`emission`, lues par l'API et le dashboard) est la couche Gold de ce projet, pas un
export MinIO redondant. Aucun besoin réel identifié pour un tel export dans les tâches à venir
(B1.9, B1.10, B2) - décision documentée plutôt que laissée à l'abandon :
[`docs/superpowers/specs/2026-08-31-b18-isolation-closure-design.md`](docs/superpowers/specs/2026-08-31-b18-isolation-closure-design.md).

### Alerting réel sur échec des DAGs (B1.9, 2026-09-01)

Les 5 DAGs Volet B envoient désormais un vrai email (Gmail SMTP,
`AIRFLOW_ALERT_EMAIL`/`AIRFLOW_SMTP_PASSWORD` dans `.env`) quand une tâche épuise ses 3 tentatives
et échoue pour de bon - jamais à chaque tentative individuelle (`email_on_retry: False`), pour ne
pas noyer une vraie panne sous des alertes de lenteur transitoire déjà couvertes par le retry
existant. Un changement de structure d'une source (champ renommé/retiré côté API) n'a pas de
détection dédiée - il se manifeste déjà comme une exception Python réelle pendant le parsing, que
`email_on_failure` couvre au même titre que n'importe quel autre échec. Décisions complètes :
[`docs/superpowers/specs/2026-09-01-b19-airflow-alerting-design.md`](docs/superpowers/specs/2026-09-01-b19-airflow-alerting-design.md).

Vérifié en direct en deux temps : un email de test SMTP direct (`airflow.utils.email.send_email`)
et un DAG jetable systématiquement en échec (`retries: 0`) ont chacun généré un vrai email reçu et
confirmé par Serge - le second correspond exactement à la tâche, au sujet
(`Airflow alert: <TaskInstance: ...>`) et à l'exception attendus. Le DAG de test a été supprimé
après vérification (jamais commité).

### B3.2 - Diffusion des KPIs réels via Mercure (2026-09-07)

Le plan officiel scope B3.2 comme « Connecter la diffusion des KPIs réels **et GreenAccess** au
hub Mercure ». Décisions complètes :
[`docs/superpowers/specs/2026-09-07-b32-mercure-realtime-kpis-design.md`](docs/superpowers/specs/2026-09-07-b32-mercure-realtime-kpis-design.md),
plan d'implémentation :
[`docs/superpowers/plans/2026-09-07-b32-mercure-realtime-kpis.md`](docs/superpowers/plans/2026-09-07-b32-mercure-realtime-kpis.md).

**Deux constats avant de coder :**

- **GreenAccess est hors de portée.** Toute la Phase B2 (B2.1 à B2.8) est au statut « Bloqué »
  dans le plan - aucune donnée GreenAccess n'existe nulle part (ni Kafka, ni TimescaleDB), il n'y
  a littéralement rien à diffuser. Même décision que B3.1 (avancé sans attendre B2 sur instruction
  explicite de l'utilisateur) : cette tâche couvre uniquement les KPIs réels du Volet B déjà
  collectés.
- **Le flux Mercure du financement était déjà réel, sans changement de code.**
  `PublishKpiSnapshotCommand` (A3.1) republie `AnalyticsService::getFinancingTrends()` /
  `getHeroStats()` - les mêmes méthodes que B3.1 a corrigées. Vérifié en direct : le payload SSE
  passe de `3 241 719 990` USD (démo seule) à `278 367 428 726` USD dès que le connecteur World
  Bank réel a tourné - aucune modification n'était nécessaire côté financement.

**Le vrai travail : rebrancher la réduction CO2 sur les données réelles.**
`AnalyticsService::getCo2Reduction()` retournait encore en dur `{available: false}` alors que
B1.4 a livré une vraie table `Emission` (840 lignes réelles, 35 des 54 pays NEV, source IEA/PNUE).
Rebranché : **variation totale des émissions entre les deux années les plus récentes disposant de
données, calculée uniquement sur l'intersection des pays présents les deux années** (jamais un
pays manquant traité comme zéro - « Absence ≠ zéro », cahier des charges 6.4). `reductionMt`
positif = réduction réelle, négatif = hausse réelle, **jamais tronqué**. Fallback
`{available: false}` conservé si moins de 2 années de données, ou aucun pays commun.

- Nouvelles méthodes `EmissionRepository::distinctYearsWithCurrentData()` / `totalsByYear()`
  (filtre `isCurrent = true`, même principe que B1.7 pour `Funding`).
- Forme de réponse enrichie : `data` devient
  `{reductionMt, latestYear, previousYear, countriesCompared}` (jamais un scalaire ; `data`
  n'avait jamais été autre chose que `null` depuis la création de l'endpoint, aucun consommateur
  réel à casser). Swagger et le hook frontend mis à jour en même temps.
- `co2ReductionMt` ajouté au payload Mercure (nullable - jamais `0` par défaut) ; la tuile CO2 de
  `VisualizationsPage.jsx` porte désormais le badge « Direct » quand la valeur arrive du hub,
  exactement comme la tuile financement.

**Vérifié en direct sur le Codespace (données PNUE réelles) :**
`GET /api/analytics/co2-reduction` →
`{"available":true,"data":{"reductionMt":-7.83,"latestYear":2023,"previousYear":2022,"countriesCompared":35}}`.
Requête SQL de contrôle directe sur `emission` : `2022 → 2023, 35 pays communs, -7.830 Mt` -
**correspondance exacte**. Les émissions ont réellement **augmenté** de 7,83 Mt sur ce périmètre
entre 2022 et 2023 - affiché honnêtement en négatif, pas maquillé en « réduction ». Payload
Mercure capté en direct :
`{"fundingTotalUsd":278367428726,"countriesCovered":54,"co2ReductionMt":-7.83,"publishedAt":"..."}`.

**Piège rencontré au bootstrap d'un Codespace neuf** (voir aussi point d'attention #11) : les
connecteurs Volet B lisent la liste des pays depuis la table `country`
(`SELECT iso_code FROM country`, décision B1.1). Sans `doctrine:fixtures:load` préalable, cette
table est vide → `_extraire()` ne fait **aucun** appel API (0,2s d'exécution, zéro log) et le DAG
rapporte quand même `success`. Toujours charger les fixtures A1.6 avant de déclencher un
connecteur sur un environnement fraîchement provisionné.

### B3.3 - Bascule démo → réel (2026-09-08)

Décisions complètes :
[`docs/superpowers/specs/2026-09-08-b33-demo-to-real-switch-design.md`](docs/superpowers/specs/2026-09-08-b33-demo-to-real-switch-design.md),
plan d'implémentation :
[`docs/superpowers/plans/2026-09-08-b33-demo-to-real-switch.md`](docs/superpowers/plans/2026-09-08-b33-demo-to-real-switch.md).

**Constat** : aucune requête de lecture ne filtrait `validation_status` nulle part - exactement le
même angle mort que B1.7 avait trouvé pour `is_current`. `GET /api/analytics/hero-stats` mélangeait
1080 lignes `funding` de démonstration (A1.6, `validation_status = 'demo'`) et 3005 lignes réelles
(`'validated'`), renvoyant `fundingRecords: 4085`.

**Corrigé** : un filtre `validation_status = 'validated'` (liste blanche, jamais liste noire)
systématique côté lecture - `FundingRepository::onlyValidated()` (nouveau helper), appliqué à
`criteriaQueryBuilder()` (listing/export), `findFinancingTrendsAggregate()`,
`findSectorDistributionAggregate()`, `findCountryDistributionAggregate()`,
`countDistinctCountries()`, `countDistinctSources()` ; même filtre inline dans
`EmissionRepository::distinctYearsWithCurrentData()`/`totalsByYear()` (B3.2) ; `AnalyticsService::
getHeroStats()` ajoute `validationStatus` au tableau de critères de `count()`. Toujours actif,
jamais un paramètre d'API ni un flag d'environnement (décision explicite).

**Hors périmètre** : `report`/`notification` (aucune tâche Volet B ne produit de vraies données
pour ces deux tables - fixtures démo conservées) ; `source`/`country`/`sector` (données de
référence partagées, jamais « démo » - `SourceFixtures` crée d'ailleurs les mêmes lignes que le
pipeline réutilise). Bascule unique décidée plutôt qu'un mécanisme progressif par source : la
démonstration A1.6 est un jeu synthétique global, pas taggé par source réelle - un « progressif
par source » n'aurait rien à activer.

**Vérifié en direct sur le Codespace** :
`GET /api/analytics/hero-stats` passe de `{"countriesCovered":54,"fundingRecords":4085,
"activeSources":6}` à `{"countriesCovered":53,"fundingRecords":3005,"activeSources":4}` - les
lignes démo (dont un pays qui n'existait qu'en démo, et 2 sources purement démo) disparaissent
intégralement. `GET /api/analytics/financing-trends` (somme des `total`) = `317 117 898 749`,
identique au `SELECT sum(amount) FROM funding WHERE is_current AND validation_status='validated'`
exécuté en direct. Suite complète : 205 tests, 763 assertions, 0 échec.

### B3.4 & B3.5 - Contrôle qualité et idempotence (2026-09-08)

Décisions et preuves complètes :
[`docs/superpowers/specs/2026-09-08-b34-b35-recette-controle-qualite.md`](docs/superpowers/specs/2026-09-08-b34-b35-recette-controle-qualite.md).

**B3.4** : les 5 agrégats analytiques exposés au frontend (tendances de financement, stats du
Hero, répartition sectorielle, répartition par pays, réduction CO2) comparés un par un à leur
requête SQL directe équivalente - correspondance exacte sur les 5. Un vrai défaut latent trouvé
en creusant `emission` avant le calcul CO2 : **12 lignes portaient `value_mt = NaN`** (Soudan du
Sud, 2000-2011) - l'API SDG des Nations Unies renvoie littéralement la chaîne `"NaN"` pour
certaines années au lieu d'omettre la ligne, et `float("NaN")` réussit silencieusement en Python.
Un `NaN` contamine toute agrégation SQL/PHP qui le contient (`SUM`/`array_sum` devient `NaN` pour
tout le groupe) - sans impact visible aujourd'hui (hors de la fenêtre des 2 années les plus
récentes utilisée par `getCo2Reduction()`), mais aurait cassé silencieusement l'endpoint et le
flux Mercure dès que cette fenêtre les aurait incluses. Corrigé dans
`pipeline/collectors/pnue.py::parse_emission()` (rejette explicitement tout `NaN`, même principe
« Absence ≠ zéro » que le reste du pipeline). Nettoyage des 12 lignes déjà en base (dev et
production) à faire manuellement : `DELETE FROM emission WHERE value_mt::text = 'NaN';`.

**B3.5** : les 5 DAGs redéclenchés manuellement sur un état déjà peuplé. **0 doublon, 0 perte**
sur toutes les sources (World Bank, GCF, AfDB, OPEC Fund, PNUE) - nombre de lignes strictement
identique avant/après pour chacune. Le seul écart observé (AfDB, montant total -0,00006%) n'est
pas une erreur de rejeu : confirmé être une vraie révision de la source IATI entre les deux
exécutions, correctement absorbée en delta par `funding_project_contribution` plutôt qu'additionnée
aveuglément. OPEC Fund confirme le cache SHA-256 (B1.5) : second déclenchement, 0 message
republié. Critère de recette 6.8 satisfait.
