# NEV Climate Data

Plateforme de collecte, structuration et diffusion de données climatiques et de financement (Volet A : application ; Volet B : pipeline de données). Ce dépôt contient actuellement les **fondations** du Volet A : environnement Docker, squelette de l'API backend Symfony, schéma de données TimescaleDB « pipeline-ready », et authentification JWT (Phase A1, tâches A1.1 à A1.4 du plan d'implémentation — voir « État d'avancement » en bas de ce document pour le détail et la suite).

## Structure du dépôt

```
nev-climate-data/
├── backend/                 Application Symfony (API REST)
│   ├── src/
│   │   ├── Controller/      Contrôleurs HTTP (ex: HealthController)
│   │   ├── Entity/          Entités Doctrine (Country, Sector, Source, User, Funding, Report, ApiKey, Notification)
│   │   └── Repository/      Repositories Doctrine
│   ├── config/               Configuration Symfony (routes, packages, bundles)
│   ├── migrations/           Migrations Doctrine (3 : schéma couche 1, couche 2, table refresh_token — voir « Schéma de données »)
│   ├── tests/                Tests automatisés (PHPUnit)
│   ├── public/                Point d'entrée HTTP (index.php)
│   └── composer.json
├── frontend/                 Réservé au frontend (Phase A2 et suivantes — vide pour l'instant)
├── docker/
│   └── backend/               Dockerfile et configuration Apache du backend
├── docker-compose.yml         Orchestration des services (backend + base de données)
├── .env.example                Modèle des variables d'environnement
└── README.md
```

> Le dossier `frontend/` est volontairement vide à ce stade : il sera peuplé lors de la Phase A2.

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

Le fichier `.env` réel n'est **jamais** versionné (voir `.gitignore`). Aucun secret n'est codé en dur dans les fichiers Docker.

> Note : le service `database` utilise l'image `timescale/timescaledb:latest-pg16` (compatible protocole PostgreSQL) depuis le Point 3 du plan (A1.3). Les variables de connexion (`DATABASE_URL`, `POSTGRES_*`) n'ont pas changé lors de ce remplacement.

### Après avoir récupéré ce travail (`git pull`) : checklist de reprise

Si tu avais déjà un environnement local avant ce commit, cinq choses ont changé et nécessitent une action :

1. **Nouvelle variable d'environnement** : ajoute `JWT_PASSPHRASE=<valeur aléatoire>` à ton `.env` local (voir `.env.example` — génère une valeur avec `openssl rand -hex 16`).
2. **Reconstruire l'image backend** (nouvelles dépendances Composer — JWT, refresh token, rate limiter) :
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
5. **Vérifier que tout est sain** :
   ```bash
   docker compose exec backend php bin/console doctrine:schema:validate
   docker compose exec -e APP_ENV=test backend php bin/console doctrine:database:create --if-not-exists
   docker compose exec -e APP_ENV=test backend php bin/console doctrine:migrations:migrate --no-interaction
   docker compose exec -e APP_ENV=test backend php bin/phpunit
   ```
   Attendu : `doctrine:schema:validate` → deux `[OK]` ; la suite de tests → 46 tests au vert.

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

## Schéma de données

Le schéma métier (section 5.3 du cahier des charges) est réparti en deux migrations Doctrine, correspondant à deux couches de dépendances, plus une troisième migration technique pour l'authentification (A1.4) :

**Couche 1 — sans clé étrangère** (`Version20260824005839`) : `Country`, `Sector`, `Source`, `User` (table `users`, car `user` est un mot réservé en PostgreSQL).

**Couche 2 — dépend de la couche 1** (`Version20260824013715`) : `Funding` (clés étrangères vers `Country`, `Sector`, `Source`), `Report` (clé étrangère optionnelle vers `Country`), `ApiKey` et `Notification` (clé étrangère vers `User`).

**`refresh_token`** (`Version20260824020255`, A1.4) : table technique du bundle d'authentification (Gesdinet), pas une entité métier du cahier des charges — stocke les jetons de rafraîchissement JWT (voir section « Authentification »).

Toutes les valeurs à faible cardinalité et stables (rôle utilisateur, type de financement, statut de validation, statut de rapport, statut de clé API, type/fiabilité de source, type de notification) sont des enums PHP natifs mappés via Doctrine, pas des tables de référence.

`Funding` porte dès maintenant les colonnes nécessaires au Volet B sans que la logique applicative du Volet A les exploite :
- `originalAmount` / `originalCurrency` / `exchangeRate` (montant dans la devise pivot USD dans `amount`, métadonnées de conversion réservées au Volet B)
- `validFrom` / `validTo` / `isCurrent` (hooks d'historisation ; le Volet A continue de faire de simples `UPDATE` en place)

Détails complets du modèle de données et des décisions de conception : voir
[`docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md`](docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md).

> **Note technique — TimescaleDB et `doctrine:migrations:diff`** : `backend/config/packages/doctrine.yaml` définit un `schema_filter` qui exclut les schémas/séquences internes de l'extension TimescaleDB (`_timescaledb_catalog`, `_timescaledb_internal`, etc.) de la comparaison de schéma Doctrine. Sans ce filtre, `doctrine:migrations:diff` et `doctrine:schema:validate` tentent de gérer (voire supprimer) ces objets internes à l'extension. Si une future migration générée contient malgré tout des `CREATE SCHEMA`/`DROP SEQUENCE` sur des objets `timescaledb*`/`_timescaledb*`, les retirer manuellement avant d'appliquer la migration.

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
| `POST /api/auth/logout` | Oui (Bearer token) | Révoque le refresh token de l'utilisateur — 204 |

### Durées de vie

- Access token : 15 minutes
- Refresh token : 30 jours, à usage unique (rotation à chaque `/api/auth/refresh`)

### Protection anti-brute-force

5 tentatives de connexion échouées par identifiant sur une fenêtre de 15 minutes déclenchent un blocage temporaire (`429 Too Many Requests`).

### Rôles

`User.role` (`Admin` / `InternalAnalyst` / `ExternalPartner`) est mappé vers `ROLE_ADMIN` / `ROLE_INTERNAL_ANALYST` / `ROLE_EXTERNAL_PARTNER` (+ `ROLE_USER` pour tout utilisateur authentifié). Le "Visiteur" du cahier des charges correspond à l'absence de compte, donc à l'absence de jeton — pas à un rôle stocké en base.

## Gestion des clés API (A1.5)

Chaque utilisateur authentifié peut générer, lister et révoquer ses propres clés API (cahier des charges 5.2.b). Détails de conception : voir le rapport final A1.5 dans l'historique de conversation du projet (pas de fichier spec dédié pour cette tâche).

### Principe de sécurité

- La clé brute (`nev_<64 caractères hex>`, 256 bits d'entropie via `random_bytes`) n'est **retournée qu'une seule fois**, à la création. Elle n'est jamais stockée : seul son hash SHA-256 l'est (colonne `key_hash`), et aucun endpoint ne le renvoie jamais.
- Une clé révoquée est immédiatement refusée par la validation, mais reste en base (traçabilité) avec `status = revoked` et `revoked_at` renseigné.

### Endpoints (authentification JWT requise — voir « Limites actuelles » ci-dessous)

| Endpoint | Description |
|---|---|
| `POST /api/api-keys` | Génère une nouvelle clé pour l'utilisateur connecté. Réponse `201` avec `key` (clé brute, à sauvegarder immédiatement). |
| `GET /api/api-keys` | Liste les clés de l'utilisateur connecté (métadonnées seulement — jamais `key` ni le hash). |
| `DELETE /api/api-keys/{id}` | Révoque une clé appartenant à l'utilisateur connecté. `404` si la clé n'existe pas ou appartient à quelqu'un d'autre (pas de `403`, pour ne pas révéler l'existence de la clé d'un tiers). |

### Utiliser une clé API

Envoyer la clé dans l'en-tête `X-API-Key` sur n'importe quelle route `/api/*` :

```bash
curl -H "X-API-Key: nev_<votre_clé>" http://localhost:8080/api/auth/me
```

L'authentification par clé API (`App\Security\ApiKeyAuthenticator`) est enregistrée sur le firewall `api` aux côtés de JWT (`security.yaml`) — les deux mécanismes coexistent, chacun ne réagissant qu'à son propre en-tête (`Authorization: Bearer` vs `X-API-Key`).

**Important — les endpoints `/api/api-keys` eux-mêmes exigent une authentification JWT et refusent une clé API** (`403`) : une clé API compromise ne doit pas pouvoir en générer d'autres. C'est une politique volontairement plus stricte que le reste de l'API, documentée dans `App\Controller\ApiKeyController::assertJwtAuthenticated()`.

### Quotas par rôle

| Rôle | Quota (requêtes/jour) |
|---|---|
| Admin | 100 000 |
| Analyste interne | 20 000 |
| Partenaire externe | 5 000 |

⚠️ **Ces valeurs sont provisoires** : ni le cahier des charges ni le plan d'implémentation ne fixent de chiffres officiels (la spec A1.3 précise seulement que `quota` est "a daily request quota"). Elles sont centralisées dans `App\Security\ApiKeyQuotaPolicy` — à ajuster dès que le client valide des valeurs définitives.

### Limites actuelles

- **Le quota n'est pas encore appliqué en usage réel** : la colonne `ApiKey.quota` porte la limite attribuée à la création, mais rien ne compte ni ne décrémente les requêtes consommées à ce stade — le schéma actuel ne porte aucun compteur d'usage ni fenêtre temporelle. L'attribution du quota par rôle est fonctionnelle ; son application (rejet au-delà du quota) reste à concevoir, probablement en A2 ou A3, avec une réflexion sur le stockage (compteur en base vs. Redis avec fenêtre glissante).
- **Aucune gestion des clés d'un autre utilisateur par un admin** n'est implémentée : le cahier des charges ne définit pas encore cette politique: seule la gestion de ses propres clés est disponible.
- **Aucune route métier n'accepte encore la clé API** au-delà de l'infrastructure elle-même : le firewall accepte `X-API-Key` sur tout `/api/*`, validé par les tests contre `/api/auth/me`, mais aucun endpoint de données (Phase A2) n'existe encore pour en tirer parti.

## Scripts de seed / fixtures (A1.6)

Un jeu de données de démonstration cohérent et reproductible, chargé via `doctrine/doctrine-fixtures-bundle` (dépendance `dev`/`test` uniquement — jamais chargée en `prod`).

### Prérequis

Aucune installation supplémentaire : `doctrine/doctrine-fixtures-bundle` est déjà dans `composer.json` (`require-dev`). Après un `git pull`, reconstruire l'image backend si ce n'est pas déjà fait (voir la checklist de reprise plus haut) — `composer install` s'exécute automatiquement au build.

### Charger les fixtures

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction
```

**Purge et recharge** : la commande ci-dessus **purge déjà** la base avant de recharger (comportement par défaut de `doctrine:fixtures:load`) — pas de commande séparée nécessaire. Rejouable autant de fois que nécessaire : toutes les valeurs sont calculées par des formules déterministes (aucun `rand()`/Faker), donc chaque rechargement reproduit exactement les mêmes lignes.

### Contenu du jeu de données

| Entité | Nombre | Détail |
|---|---|---|
| `Country` | **54** | Les 54 pays membres de l'ONU en Afrique, classés en 5 régions (Afrique du Nord, de l'Ouest, centrale, de l'Est, australe) — liste explicite dans `CountryFixtures.php`, pas générée |
| `Sector` | **5** | Renewable Energy, Sustainable Transport, Agriculture, Forestry, Adaptation — repris tels quels des exemples déjà présents dans `docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md` |
| `Source` | 4 | Une par valeur de `SourceType` (API officielle, rapport PDF, évènement GreenAccess, démonstration interne) |
| `User` | 3 | Un par rôle (`Admin`, `InternalAnalyst`, `ExternalPartner`) |
| `Funding` | **1 080** | 54 pays × 5 secteurs × 4 années (2022-2025) — voir « Volume et répartition » |
| `Report` | 6 | Mélange rapports globaux / régionaux / par pays, statuts `Draft`/`Published` |
| `Notification` | 7 | Réparties sur les 3 utilisateurs, mélange lu/non lu |
| `ApiKey` | 2 | Une active, une révoquée — juste de quoi tester la relation avec `User` |

### Volume et répartition des données Funding

**Toutes les données `Funding` de ces fixtures utilisent `ValidationStatus::Demo`** — jamais `ValidationStatus::Validated` (règle 5.7 du cahier des charges : jeu de démonstration marqué comme tel).

- **Plage d'années** : 2022-2025 (4 années). Aucune plage officielle n'étant définie dans le cahier des charges ni le plan, ce choix est documenté ici comme provisoire.
- **Volume** : 1 080 lignes = 54 pays × 5 secteurs × 4 années, en couverture complète (pas d'échantillonnage) — choisi plutôt qu'une plage d'années plus longue précisément pour garder ce produit croisé rapide à charger tout en couvrant chaque pays/secteur sur une série temporelle complète (utile pour les futurs graphiques de tendance).
- **Répartition par type** : exactement 360 `Public` / 360 `Private` / 360 `Multilateral` (rotation déterministe selon pays × secteur × année).
- **Montants** : formule déterministe (base par secteur × facteur pays × croissance annuelle × multiplicateur de type), jamais de `float` PHP — toujours une chaîne formatée à 2 décimales, conforme au typage `DECIMAL` de la colonne. Ce sont des montants illustratifs, pas des données financières réelles.
- **Conversion de devise** : `originalAmount`/`originalCurrency`/`exchangeRate` renseignés uniquement sur les financements `Multilateral` (EUR, taux fixe illustratif 1,08) pour exercer ces champs réservés au Volet B, sans prétendre représenter un vrai taux de change.

### Utilisateurs de démonstration

⚠️ **Environnement local/développement uniquement — jamais de secret réel.**

| Rôle | Email | Mot de passe |
|---|---|---|
| Admin | `admin@nev-climate-data.demo` | `ClimateDemo2026!` |
| Analyste interne | `analyste@nev-climate-data.demo` | `ClimateDemo2026!` |
| Partenaire externe | `partenaire@nev-climate-data.demo` | `ClimateDemo2026!` |

Mot de passe hashé via `password_hash()` (jamais stocké en clair). Les clés API de démonstration (`ApiKeyFixtures.php`) sont générées à partir de valeurs brutes fixes et non secrètes, hashées avec `ApiKeyService::hashKey()` (le même algorithme qu'en production) — **ces clés brutes ne sont pas exploitables** (non documentées, non fonctionnelles pour un usage réel) et ne sont volontairement pas reproduites ici ; pour obtenir une clé utilisable, générer la vôtre via `POST /api/api-keys` après connexion.

### Limites actuelles

- Le volume de `Funding` (1 080 lignes) est pensé pour la démonstration et le développement des dashboards — pas pour un test de charge.
- Les montants et taux de change sont illustratifs, générés par formule, pas des données réelles.
- Pas de fixtures pour `RefreshToken` (entité technique, générée uniquement par le mécanisme d'authentification — jamais de faux token créé pour remplir la base).

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

## Points d'attention (pièges déjà rencontrés — à ne pas réintroduire)

Ces problèmes ont été rencontrés et corrigés pendant A1.3/A1.4. Ils ne sont pas évidents et peuvent facilement revenir si on n'y fait pas attention en continuant le projet :

1. **`access_control` protège désormais tout `/api/*` par défaut.** Depuis A1.4, `security.yaml` exige `IS_AUTHENTICATED_FULLY` sur `^/api` sauf exceptions explicites (`/api/auth/login`, `/api/auth/refresh`, `/api/doc`, `/api/health`). **Tout nouvel endpoint public** (Phase A2 : statistiques d'en-tête, recherche globale, etc. — cf. cahier des charges 5.2, « Accès public ») **doit être ajouté explicitement** à la liste `access_control` de `backend/config/packages/security.yaml`, sinon il renverra 401 par défaut.

2. **Un authenticator basé sur `check_path` (`json_login`, `refresh-jwt`) exige une route réelle**, même sans contrôleur. Le routeur Symfony s'exécute *avant* le firewall ; sans route correspondante, il renvoie 404 avant même que l'authenticator ait une chance de répondre. Voir `backend/config/routes.yaml` (`api_auth_login`, `api_auth_refresh`) pour le modèle à suivre si un futur endpoint d'auth est ajouté.

3. **TimescaleDB pollue `doctrine:migrations:diff`.** L'extension crée ses propres schémas/séquences internes (`_timescaledb_catalog`, `_timescaledb_internal`, etc.). Un `schema_filter` dans `backend/config/packages/doctrine.yaml` les exclut de la comparaison Doctrine — sans lui, `doctrine:migrations:diff` génère des `DROP SEQUENCE`/`CREATE SCHEMA` dangereux sur ces objets internes. **Toujours relire une migration générée** avant de l'appliquer ; si des instructions sur des objets `timescaledb*`/`_timescaledb*` apparaissent malgré le filtre, les retirer manuellement (voir les migrations existantes pour l'exemple).

4. **Les tests d'intégration/fonctionnels qui écrivent en base doivent être ré-exécutables.** Pattern à reprendre (voir `tests/Integration/SchemaLayer1Test.php`, `SchemaLayer2Test.php`, `tests/Controller/AuthenticationControllerTest.php`) : ouvrir une transaction dans `setUp()`, la annuler (`rollBack()`) dans `tearDown()`. Sans ça, une donnée insérée par un test (ex. un email unique) fait échouer toute ré-exécution ultérieure de la suite.

5. **`KernelBrowser` redémarre le kernel (nouvelle connexion DB) à partir de la 2ᵉ requête d'un même test.** Un test qui enchaîne plusieurs requêtes HTTP (ex. login puis refresh) doit appeler `$client->disableReboot()` avant la première requête, sinon la transaction ouverte pour les données de test devient invisible à la 2ᵉ requête (échec « Not Found » trompeur, qui ressemble à un bug métier mais n'en est pas un).

6. **Le rate limiter de throttling (`login_throttling`) est stocké en cache filesystem, pas en base.** Il n'est donc *pas* nettoyé par le pattern de transaction du point 4. Un test de throttling qui réutilise toujours le même identifiant peut échouer de façon aléatoire selon l'historique des exécutions précédentes — utiliser un identifiant unique par exécution (voir `testSixthFailedLoginAttemptIsThrottled`).

7. **Ne jamais committer de vrai secret dans `backend/.env`.** Ce fichier est suivi par git (contrairement au `.env` racine) et ne doit contenir que des placeholders (`ChangeMe`, comme `APP_SECRET`/`JWT_PASSPHRASE`) — les vraies valeurs passent uniquement par `docker-compose.yml` → `.env` racine (gitignoré). Un recipe Symfony Flex a généré une vraie passphrase en clair dans `backend/.env` lors de l'installation du bundle JWT ; elle a été retirée avant tout commit. Vérifier ce point après toute installation de nouveau bundle via `composer require`.

8. **Un `AuthenticationFailureHandlerInterface` référencé par `security.yaml` ne peut pas être décoré (`decorates:`) de façon fiable.** Les factories `json_login`/`form_login` clonent la définition du service en un service anonyme lors de la compilation du conteneur, ce qui contourne le mécanisme de décoration Symfony. Pour personnaliser un handler, créer un service dédié référencé directement dans `security.yaml`, pas un décorateur (voir `App\Security\LoginFailureHandler`).

9. **La liste d'extensions PHP du job `phpunit` (`.gitlab-ci.yml`) est dupliquée depuis `docker/backend/Dockerfile`, pas partagée.** Le job de test tourne dans une image PHP générique, pas dans l'image Docker du projet (choix documenté dans le spec A1.7, pour garder le pipeline rapide sur chaque push). Si une extension PHP est ajoutée/retirée du `Dockerfile`, il faut penser à répercuter le changement dans `.gitlab-ci.yml` — rien ne le fait automatiquement, et un oubli ne casse rien immédiatement (juste une divergence silencieuse entre l'environnement testé et l'environnement réel).

## État d'avancement

**Fait (Phase A1 — Fondations, ~13 tâches sur le plan) :**

| Tâche | Contenu | Statut |
|---|---|---|
| A1.1 | Environnement Docker Compose | ✅ Fait (Oumar) |
| A1.2 | Squelette API Symfony | ✅ Fait (Oumar) |
| A1.3 | Schéma TimescaleDB « pipeline-ready » (8 entités + enums, 2 migrations) | ✅ Fait |
| A1.4 | Authentification JWT (login/refresh/logout/me, rôles, anti-brute-force) | ✅ Fait |
| A1.5 | Gestion des clés API (génération, quotas, révocation) | ✅ Fait — application du quota (compteur d'usage) non encore implémentée, voir « Limites actuelles » |
| A1.6 | Scripts de seed/fixtures (jeu de données de démonstration) | ✅ Fait |
| A1.7 | Pipeline CI/CD (build, tests, publication d'image) | ✅ Fait — publie l'image sur le Container Registry, ne déploie pas (voir section CI/CD) |
| A1.8 | Recette Auth → API → Base de données | ⬜ Reste à faire |

**Reste à faire :** A1.7 et A1.8 (fin de Phase A1), puis Phase A2 (extraction de données, export, dashboards, recherche, notifications, i18n, menu mobile, section Rapports), Phase A3 (temps réel, sécurité, performance, mise en production), puis Volet B (pipeline de données réelles). Détail complet, échéances et responsables : `Plan_Implementation_NEV_Climate_Data.xlsx`, onglet « Plan d'implémentation ».

**Documentation de conception disponible** pour tout ce qui est fait jusqu'ici (décisions prises, alternatives écartées, justifications) :
- [`docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md`](docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md) + [`docs/superpowers/plans/2026-08-22-a13-timescaledb-schema.md`](docs/superpowers/plans/2026-08-22-a13-timescaledb-schema.md)
- [`docs/superpowers/specs/2026-08-24-a14-jwt-authentication-design.md`](docs/superpowers/specs/2026-08-24-a14-jwt-authentication-design.md) + [`docs/superpowers/plans/2026-08-24-a14-jwt-authentication.md`](docs/superpowers/plans/2026-08-24-a14-jwt-authentication.md)
