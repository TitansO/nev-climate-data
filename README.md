# NEV Climate Data

Plateforme de collecte, structuration et diffusion de données climatiques et de financement (Volet A : application ; Volet B : pipeline de données). La Phase A1 (fondations : environnement Docker, API Symfony, schéma TimescaleDB « pipeline-ready », authentification JWT, clés API, fixtures, CI/CD) est close. La Phase A2 est bien avancée : extraction de données filtrée/paginée, export CSV/Excel (synchrone et asynchrone), notifications par utilisateur (navigables), dashboards analytiques mis en cache, statistiques d'en-tête, recherche globale insensible à la casse/aux accents, internationalisation FR/EN et menu mobile complet sont tous opérationnels (A2.1-A2.12) - voir « État d'avancement » en bas de ce document pour le détail. Volet B (pipeline de données) a démarré en parallèle (infrastructure Kafka/Airflow/MinIO, connecteur Banque Mondiale).

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
├── docker/
│   └── backend/               Dockerfile et configuration Apache du backend
├── docker-compose.yml         Orchestration des services (backend + base de données)
├── .env.example                Modèle des variables d'environnement
└── README.md
```

> Détail complet du frontend (structure, identité visuelle, comment le lancer) : [`frontend/README.md`](frontend/README.md).

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
| Admin | `admin@nev-climate-data.demo` | `ClimateDemo2026!` |
| Analyste interne | `analyste@nev-climate-data.demo` | `ClimateDemo2026!` |
| Partenaire externe | `partenaire@nev-climate-data.demo` | `ClimateDemo2026!` |

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
- **Couverture actuelle** : les 12 pages statiques (nav commune, footer commun, contenu propre à chaque page). Le contenu injecté dynamiquement par JavaScript à l'exécution (lignes du tableau de données, éléments de notification, libellés de rôle sur `account-profile.html`, texte du bouton d'export pendant le traitement) n'est **pas** traduit par ce mécanisme - hors périmètre pour l'instant.
- Pour ajouter une nouvelle chaîne traduisible : poser l'attribut `data-i18n` sur l'élément, ajouter la même clé dans les deux fichiers JSON.

### Menu mobile (A2.12)

Le mécanisme de bascule du menu hamburger (`#navbarToggler` / `#navbarCollapse`, voir `assets/js/main.js`) existait déjà mais le panneau mobile n'affichait que les liens de navigation : recherche, notifications et connexion étaient dans un bloc `hidden … sm:flex` invisible sous 640px et jamais inclus dans le menu ouvert. Ce bloc a été déplacé à l'intérieur de `#navbarCollapse` (empilé sous les liens en mobile ; remis en ligne à droite via `lg:flex lg:justify-between` en desktop, layout desktop inchangé) sur les 10 pages qui le partagent.

**Piège à ne pas réintroduire** : Tailwind CSS v4 est compilé à l'avance (`frontend/src/css/tailwind.css`, généré depuis `frontend/src/input.css` par `npm run build` - voir `frontend/package.json`). Ajouter une classe Tailwind dans un fichier HTML sans relancer `npm run build` derrière n'a **aucun effet visible** (la classe n'existe simplement pas dans le CSS livré) - c'est exactement ce qui a cassé le rendu du header une première fois pendant le développement de A2.12.

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

## Points d'attention (pièges déjà rencontrés - à ne pas réintroduire)

Ces problèmes ont été rencontrés et corrigés pendant A1.3 à A1.7. Ils ne sont pas évidents et peuvent facilement revenir si on n'y fait pas attention en continuant le projet :

1. **`access_control` protège désormais tout `/api/*` par défaut.** Depuis A1.4, `security.yaml` exige `IS_AUTHENTICATED_FULLY` sur `^/api` sauf exceptions explicites (`/api/auth/login`, `/api/auth/refresh`, `/api/doc`, `/api/health`). **Tout nouvel endpoint public** (Phase A2 : statistiques d'en-tête, recherche globale, etc. - cf. cahier des charges 5.2, « Accès public ») **doit être ajouté explicitement** à la liste `access_control` de `backend/config/packages/security.yaml`, sinon il renverra 401 par défaut.

2. **Un authenticator basé sur `check_path` (`json_login`, `refresh-jwt`) exige une route réelle**, même sans contrôleur. Le routeur Symfony s'exécute *avant* le firewall ; sans route correspondante, il renvoie 404 avant même que l'authenticator ait une chance de répondre. Voir `backend/config/routes.yaml` (`api_auth_login`, `api_auth_refresh`) pour le modèle à suivre si un futur endpoint d'auth est ajouté.

3. **TimescaleDB pollue `doctrine:migrations:diff`.** L'extension crée ses propres schémas/séquences internes (`_timescaledb_catalog`, `_timescaledb_internal`, etc.). Un `schema_filter` dans `backend/config/packages/doctrine.yaml` les exclut de la comparaison Doctrine - sans lui, `doctrine:migrations:diff` génère des `DROP SEQUENCE`/`CREATE SCHEMA` dangereux sur ces objets internes. **Toujours relire une migration générée** avant de l'appliquer ; si des instructions sur des objets `timescaledb*`/`_timescaledb*` apparaissent malgré le filtre, les retirer manuellement (voir les migrations existantes pour l'exemple).

4. **Les tests d'intégration/fonctionnels qui écrivent en base doivent être ré-exécutables.** Pattern à reprendre (voir `tests/Integration/SchemaLayer1Test.php`, `SchemaLayer2Test.php`, `tests/Controller/AuthenticationControllerTest.php`) : ouvrir une transaction dans `setUp()`, la annuler (`rollBack()`) dans `tearDown()`. Sans ça, une donnée insérée par un test (ex. un email unique) fait échouer toute ré-exécution ultérieure de la suite.

5. **`KernelBrowser` redémarre le kernel (nouvelle connexion DB) à partir de la 2ᵉ requête d'un même test.** Un test qui enchaîne plusieurs requêtes HTTP (ex. login puis refresh) doit appeler `$client->disableReboot()` avant la première requête, sinon la transaction ouverte pour les données de test devient invisible à la 2ᵉ requête (échec « Not Found » trompeur, qui ressemble à un bug métier mais n'en est pas un).

6. **Le rate limiter de throttling (`login_throttling`) est stocké en cache filesystem, pas en base.** Il n'est donc *pas* nettoyé par le pattern de transaction du point 4. Un test de throttling qui réutilise toujours le même identifiant peut échouer de façon aléatoire selon l'historique des exécutions précédentes - utiliser un identifiant unique par exécution (voir `testSixthFailedLoginAttemptIsThrottled`).

7. **Ne jamais committer de vrai secret dans `backend/.env`.** Ce fichier est suivi par git (contrairement au `.env` racine) et ne doit contenir que des placeholders (`ChangeMe`, comme `APP_SECRET`/`JWT_PASSPHRASE`) - les vraies valeurs passent uniquement par `docker-compose.yml` → `.env` racine (gitignoré). Un recipe Symfony Flex a généré une vraie passphrase en clair dans `backend/.env` lors de l'installation du bundle JWT ; elle a été retirée avant tout commit. Vérifier ce point après toute installation de nouveau bundle via `composer require`.

8. **Un `AuthenticationFailureHandlerInterface` référencé par `security.yaml` ne peut pas être décoré (`decorates:`) de façon fiable.** Les factories `json_login`/`form_login` clonent la définition du service en un service anonyme lors de la compilation du conteneur, ce qui contourne le mécanisme de décoration Symfony. Pour personnaliser un handler, créer un service dédié référencé directement dans `security.yaml`, pas un décorateur (voir `App\Security\LoginFailureHandler`).

9. **La liste d'extensions PHP du job `phpunit` (`.gitlab-ci.yml`) est dupliquée depuis `docker/backend/Dockerfile`, pas partagée.** Le job de test tourne dans une image PHP générique, pas dans l'image Docker du projet (choix documenté dans le spec A1.7, pour garder le pipeline rapide sur chaque push). Si une extension PHP est ajoutée/retirée du `Dockerfile`, il faut penser à répercuter le changement dans `.gitlab-ci.yml` - rien ne le fait automatiquement, et un oubli ne casse rien immédiatement (juste une divergence silencieuse entre l'environnement testé et l'environnement réel).

10. **`.devcontainer/devcontainer.json` ne doit PAS utiliser `dockerComposeFile` + `service` pour orchestrer toute la stack.** Cette combinaison fait démarrer *tous* les services du `docker-compose.yml` (db, redis, backend, backend-worker, et désormais Kafka/Airflow/MinIO du Volet B) comme étape bloquante de la création même du Codespace. En pratique ça échoue de façon fiable : le healthcheck de TimescaleDB (50s de budget) perd la course contre la compilation de l'extension PHP `intl` (~90s), et le Codespace bascule en conteneur de secours (pas de `docker` CLI, `/home/codespace` non inscriptible). Le fichier utilise à la place une image de base simple + la feature `docker-outside-of-docker` (CLI Docker disponible, mais rien d'auto-orchestré) et la feature `sshd` (sans elle, `gh codespace ssh` échoue avec « no SSH server installed » sur cette image minimale - l'image par défaut de Codespaces en a un, celle-ci non). `docker compose up -d` reste une étape manuelle après la création du Codespace, exactement comme en local.

11. **Un Codespace fraîchement créé n'a ni `.env` racine, ni trousseau JWT, ni fixtures chargées - `docker compose up -d` seul ne suffit pas.** Trois pièges rencontrés en re-provisionnant un Codespace : (a) `nev-climate-data-db` reste `unhealthy` avec l'erreur Postgres « superuser password is not specified » tant que `.env` (gitignoré, jamais cloné) n'a pas été recréé depuis `.env.example` avec de vraies valeurs ; (b) une fois la base up, la connexion échoue en 500 « Internal Server Error » tant que `backend/config/jwt/` (gitignoré) n'a pas été régénéré via `docker compose exec backend php bin/console lexik:jwt:generate-keypair --overwrite` ; (c) même avec un backend fonctionnel, le frontend affiche des compteurs à zéro et des listes vides tant que `docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction` n'a pas été rejoué (une base neuve est un schéma vide, pas un jeu de données). Voir « Après avoir récupéré ce travail » ci-dessus pour la checklist complète.

12. **Un port de Codespace en visibilité "private" fait échouer un `fetch()` cross-origin avec un faux message CORS.** Ouvrir la page dans le navigateur (navigation complète) fonctionne car GitHub complète la redirection d'authentification (`https://<nom>-<port>.app.github.dev` → `github.dev/pf-signin` → retour), mais un `fetch()` JS depuis une autre origine ne peut pas suivre cette redirection interactive : GitHub répond `302` sans aucun en-tête CORS, et le navigateur l'affiche comme "No 'Access-Control-Allow-Origin' header is present" - alors que la config CORS du backend est correcte. Diagnostic : `curl -i <url-forwardée>/api/health` sans authentification renvoie `302` vers `github.dev/pf-signin` si le port est privé. Fix ponctuel : `gh codespace ports visibility <port>:public -c <nom-du-codespace>` (ou depuis VS Code : onglet Ports → clic droit → Port Visibility → Public) - mais ce réglage n'est pas persisté dans le code : GitHub Codespaces le réinitialise à "private" dès que le port est complètement recréé (ex. `docker compose down` puis `up`), pas seulement redémarré. **Fix définitif** : `.devcontainer/devcontainer.json` déclare `portsAttributes` avec `visibility: "public"` pour 8080 et 8123 - une fois le Codespace reconstruit une fois avec ce fichier, la visibilité ne se réinitialise plus jamais, quel que soit le nombre de `docker compose down`/`up`.

13. **`docker-compose.yml` interpole `$` avant que Docker Compose ne lise la valeur.** Une valeur par défaut (`${VAR:-...}`) contenant un `$` littéral (ex. une regex avec ancres `^...$`) doit doubler ce `$` en `$$`, sinon Compose tente de le résoudre comme une référence de variable (vide) et le tronque silencieusement. Voir `CORS_ALLOWED_ORIGIN_REGEX` dans `docker-compose.yml` pour l'exemple.

14. **Apache ne transmet pas l'en-tête `Authorization` à PHP par défaut.** Sans `CGIPassAuth On` (présent dans `docker/backend/vhost.conf` depuis A1.8), toute requête JWT (`Authorization: Bearer ...`) ou clé API (`X-API-Key`) échoue avec un faux `401 "JWT Token not found"` - **contre le vrai serveur Apache qui tourne**, alors que la suite PHPUnit (`WebTestCase`/`KernelBrowser`) passe quand même au vert, puisqu'elle appelle le noyau Symfony directement sans jamais passer par Apache. Bug trouvé pendant la recette A1.8 (invisible dans 65 tests automatisés), corrigé, non-régression vérifiée. Si un jour le `Dockerfile`/`vhost.conf` est réécrit (ex. passage à php-fpm + nginx), s'assurer que l'équivalent (`fastcgi_pass_header Authorization` pour nginx, ou configuration native pour php-fpm) est bien présent.

15. **Le pipeline Python (`pipeline/`) écrit directement en base, sans passer par l'API Symfony.** Toute évolution du schéma `funding` (colonnes, contraintes) impacte donc potentiellement deux codebases séparées (`backend/src/Entity/Funding.php` ET `pipeline/processors/funding_validator.py`) qui doivent rester manuellement synchronisées - rien ne le vérifie automatiquement. La clé de dédup de B1.1 en est un exemple concret : elle existe à la fois dans l'attribut Doctrine `#[ORM\UniqueConstraint]` sur `Funding` (un **index unique partiel**, `WHERE is_current = true` - pas une contrainte plate, voir point 16) et dans la logique Python d'upsert (`upsert_funding()`, un `SELECT` puis `UPDATE`/`INSERT` explicite, pas un `ON CONFLICT` SQL). Si l'un change sans l'autre, l'échec est silencieux jusqu'à ce qu'une vraie collision de données le révèle.

16. **Une contrainte d'unicité sur une table historisée (SCD Type 2 : `is_current`/`valid_from`/`valid_to`) ne peut pas être une contrainte plate.** `funding` a des colonnes d'historisation depuis A1.3 ; une `UniqueConstraint` classique sur `(source_id, country_id, sector_id, year, funding_type)` rejette la deuxième version historisée d'une même clé dès son insertion, alors que l'historisation dépend justement de pouvoir garder plusieurs lignes partageant cette clé (une par version, une seule `is_current = true`). Solution : un **index unique partiel** Postgres, exprimé côté Doctrine via `options: ['where' => 'is_current = true']` sur l'attribut `#[ORM\UniqueConstraint]` (Doctrine ORM 3.6+ ; confirmé fonctionnel avec `doctrine/dbal`). **Limitation connue à ne pas essayer de corriger** : `doctrine:schema:validate` et `doctrine:schema:update --dump-sql` rapportent *toujours* cet index comme désynchronisé (proposant un `DROP`/`CREATE` identique à lui-même), car le comparateur de schéma Postgres de Doctrine/DBAL ne relit pas la clause `WHERE` d'un index partiel lors de l'introspection. Vérifier l'état réel avec `\d funding` dans `psql`, pas avec `schema:validate`, pour cette table.

17. **`kafka-python` (PyPI) ne fonctionne pas sous Python 3.12** - son module vendoré `six` échoue à l'import (`ModuleNotFoundError: No module named 'kafka.vendor.six.moves'`). Utiliser `kafka-python-ng` à la place (fork activement maintenu, même espace de nommage `kafka`, aucun changement de code applicatif) - voir `pipeline/requirements.txt` et la variable `_PIP_ADDITIONAL_REQUIREMENTS` du service `airflow` dans `docker-compose.yml`.

18. **Airflow place `DAGS_FOLDER` lui-même sur `sys.path`, pas son dossier parent.** Un DAG qui importe un package situé un niveau au-dessus de `DAGS_FOLDER` (ici : `pipeline/dags/` contient les DAGs, mais `from pipeline.collectors... import ...` a besoin que `pipeline/` - son parent - soit importable) échoue avec `ModuleNotFoundError` tant que ce parent n'est pas explicitement ajouté au `PYTHONPATH` du service `airflow` dans `docker-compose.yml`.

19. **Les codes pays de l'API World Bank (`countrycode_exact`) sont en alpha-2, pas alpha-3.** `Country.isoCode` (NEV, depuis A1.3) est en alpha-3 (`SEN`). Passer directement `country.iso_code` à l'API renvoie silencieusement 0 projet pour chaque pays (pas d'erreur, juste un résultat vide) - vérifié en direct : `SEN` → 0 projets, `SN` → 264. Toujours convertir via `pycountry` avant d'appeler l'API (voir `pipeline/dags/collecte_worldbank.py`).

20. **Une requête Solr non guillemetée sur un identifiant contenant des tirets fait un matching flou, pas une correspondance exacte.** `reporting_org_ref:XM-DAC-41317` (sans guillemets) tokenize sur les tirets et retourne ~269 000 documents sans rapport, contre 350 avec `reporting_org_ref:"XM-DAC-41317"` (guillemets = expression exacte). Tout code interrogeant l'API IATI Datastore avec un identifiant contenant des tirets doit guillemeter sa valeur dans le paramètre `q=`.

21. **Un champ "provider_org" d'une API ne garantit pas qu'il identifie le vrai payeur.** Sur l'API IATI Datastore, `transaction_provider_org_ref` vaut toujours l'identifiant du GCF sur *toutes* les transactions de type "Commitment" d'une activité GCF - y compris celles explicitement décrites comme "Co-financing commitment" (argent d'autres bailleurs, que GCF enregistre pour la transparence mais qui n'est pas son propre argent). Le seul signal fiable trouvé est un champ texte libre (`transaction_description_narrative`), pas un champ codé - voir `pipeline/collectors/gcf.py::_gcf_commitment_summary` et la décision 4 de la spec B1.2. Toujours vérifier qu'un champ apparemment codé («provider», «owner», «source») identifie vraiment ce que son nom suggère, sur des données réelles, avant de s'appuyer dessus pour un calcul financier.

22. **Le scheduler Airflow peut rester marqué "vivant" (`airflow jobs check`) tout en n'exécutant plus aucune tâche.** Rencontré pendant la vérification end-to-end de B1.2 : plusieurs runs déclenchés restaient bloqués en `queued` indéfiniment, `airflow jobs check --job-type SchedulerJob` répondait pourtant "Found one alive job" et les logs ne montraient plus aucune activité `scheduler` depuis plusieurs minutes (heartbeat du `triggerer` toujours présent, celui du scheduler silencieux). Un `docker compose restart airflow` seul n'a pas suffi ; il a fallu un `docker compose up -d --force-recreate airflow` pour repartir sur un scheduler sain. Si des runs restent bloqués en `queued` plus d'une minute ou deux malgré un scheduler "vivant", ne pas attendre plus longtemps - recréer le conteneur directement.

23. **Tailwind CSS v4 est compilé à l'avance - une classe ajoutée dans un fichier HTML n'a aucun effet tant que `npm run build` n'a pas été relancé.** `frontend/src/css/tailwind.css` (le fichier réellement chargé par les pages) est généré depuis `frontend/src/input.css` en scannant les fichiers HTML/JS du projet pour ne garder que les classes utilisées (voir `frontend/package.json`). Ajouter une classe Tailwind à une page sans reconstruire produit un rendu silencieusement cassé (la classe est simplement absente du CSS livré, pas d'erreur console) - c'est exactement ce qui a cassé le rendu du header pendant le développement de A2.12, corrigé en relançant `npm run build` et en resynchronisant le fichier compilé.

24. **`doctrine:schema:update` ne connaît que les mappings d'entités Doctrine - il est aveugle au SQL brut d'une migration (ex. `CREATE EXTENSION`).** Utiliser `doctrine:schema:update --force` puis `doctrine:migrations:version --add --all` pour « rattraper » l'état des migrations (raccourci déjà documenté au point 11 pour un Codespace neuf) marque une migration comme appliquée sans exécuter son contenu réel. Piège rencontré pendant la recette A2.14 : la migration `Version20260827160000` (`CREATE EXTENSION IF NOT EXISTS unaccent`, A2.8) était marquée exécutée alors que l'extension n'existait pas réellement sur la base - `GET /api/search` échouait en 500 (`function unaccent(text) does not exist`) sur le serveur réel tout en passant en tests automatisés (la base de test, elle, avait été migrée normalement). Si ce raccourci est utilisé, vérifier ensuite manuellement toute instruction SQL non-ORM des migrations concernées (`CREATE EXTENSION`, `CREATE INDEX` conditionnel, etc.) - `doctrine:schema:update --dump-sql` ne les détectera jamais comme manquantes.

21. **Une pagination qui avance d'une taille de page fixe plutôt que du nombre de documents réellement reçus peut s'arrêter trop tôt.** Bug réel trouvé en écrivant le collecteur BAD (`pipeline/collectors/afdb.py`) : si une page renvoie moins de documents que demandé alors qu'il en reste, avancer le curseur de `PAGE_SIZE` au lieu de `len(docs)` fait dépasser le total réel et la boucle s'arrête avant la fin. Peu probable en pratique contre un vrai backend Solr (une page non-finale renvoie normalement exactement `rows` documents), mais la version robuste (avancer du nombre réel reçu) coûte la même chose à écrire et protège contre ce cas.

22. **Une API "gratuite, sans clé" peut quand même avoir une vraie limite de débit stricte (ex. 1 requête/seconde), indépendante du quota journalier.** L'offre "Exploratory" de l'API IATI Datastore (utilisée par B1.2 et B1.3) a ce comportement : enchaîner plusieurs requêtes de pagination sans pause (fonctionnait très bien pour B1.2, une seule requête suffisait) a fait échouer B1.3 de façon répétée et déroutante en `429 Too Many Requests` — y compris juste après un passage à minuit, ce qui a d'abord fait croire à tort à un quota journalier épuisé plutôt qu'à un débit trop rapide. Avant d'implémenter une pagination multi-requêtes contre une API tierce, toujours vérifier explicitement sa politique de débit (pas seulement son quota total) et ajouter une pause entre requêtes en conséquence - voir `PAGINATION_DELAY_SECONDS` dans `pipeline/collectors/afdb.py`.

23. **Deux tests indépendants qui interrogent la même base de données partagée peuvent se marcher dessus si leurs requêtes ne sont pas suffisamment précises.** Un test comparant un nombre de lignes filtré uniquement par pays/année (sans filtrer par source) a compté à tort de vraies données de production accumulées par les runs Airflow réels des connecteurs précédents (B1.1/B1.2), en plus des lignes que le test venait d'insérer lui-même. Sur une base de développement partagée entre tests et pipeline réel (contrairement à la base de test, réinitialisée et isolée), toujours scoper une assertion de comptage aux identifiants exacts (source, montants...) que le test contrôle - jamais une plage large en espérant qu'elle reste vide par ailleurs.

24. **Une "vraie API du fournisseur institutionnel attendu" peut ne pas exister du tout, même quand un fournisseur alternatif documenté et fonctionnel existe pour les mêmes données.** B1.4 (connecteur "PNUE") en est l'exemple : après 12+ vérifications réelles (API PNUE de 2016 mise hors service, portail SDG brandé PNUE vide, WESR sans API publique documentée, MapX explicitement déconseillé pour un usage tiers, rapports phares du PNUE agrégés au niveau G20 sans donnée pour la plupart des pays NEV), la seule source réelle et alimentée avec la bonne granularité pays s'est révélée être l'API SDG des Nations Unies, dont les données CO2 sont elles-mêmes attribuées à l'IEA, pas au PNUE. Documenter cette attribution explicitement (comme le XDR d'AfDB en B1.3) plutôt que de la masquer derrière le nom de la tâche.

25. **Un même indicateur d'une API peut recouvrir plusieurs dimensions incompatibles sous le même code de série.** La série SDG `EN_ATM_CO2` renvoie à la fois le total national réel (`Activity: "TOTAL"`) et un sous-ensemble manufacturier (`Activity: "ISIC4_C10T32X19"`) pour la même paire pays/année, avec des valeurs différentes. Ne jamais publier une ligne d'API sans avoir vérifié en direct quelles dimensions elle porte et lesquelles constituent réellement la grandeur recherchée.

26. **Toutes les tables historisées SCD2 de ce projet ne partagent pas la même sémantique d'upsert.** `funding` additionne les nouveaux messages pour une même clé (un flux d'événements de financement cumulatifs) ; `emission` (B1.4) remplace la valeur courante par la nouvelle (une statistique annuelle unique, régulièrement révisée par sa source). Vérifier quelle sémantique s'applique avant d'écrire une logique d'upsert pour une future table historisée - copier `upsert_funding()` par réflexe serait incorrect ici.

27. **`pycountry.countries.get(numeric=...)` exige un code numérique ISO à 3 chiffres avec zéros de tête - une API tierce ne le fournit pas forcément ainsi.** Bug réel trouvé pendant la vérification bout-en-bout de B1.4 : l'API SDG renvoie `geoAreaCode` sans padding (`"24"` pour l'Angola) alors que `pycountry` stocke ce même code en interne comme `"024"` - la recherche inverse échouait silencieusement et mettait en quarantaine à tort des pays réels (Angola confirmé en direct). Corrigé en évitant complètement cette conversion inverse : `pipeline/collectors/pnue.py` fait transiter le `country_iso` déjà connu de l'appelant (`collect_and_publish`) plutôt que de le re-dériver d'un second lookup numérique fragile. Plus généralement : préférer transmettre une donnée déjà connue plutôt que de la recalculer par un chemin qui peut silencieusement échouer sur un format inattendu.

28. **`docker compose exec <service> php bin/phpunit` peut ignorer le `<server name="APP_ENV" value="test" force="true"/>` de `phpunit.dist.xml`.** Le service `backend` a un vrai `APP_ENV=dev` défini dans `docker-compose.yml`, hérité par PHP dans `$_ENV`. `KernelTestCase::createKernel()` (Symfony) lit `$_ENV` **avant** `$_SERVER`, donc l'environnement réel du conteneur l'emporte silencieusement sur la valeur forcée par PHPUnit - la suite tourne alors en `dev`, où `framework.test: true` (bloc `when@test`) n'est jamais actif, d'où une cascade d'erreurs `"Could not find service test.service_container"`. Contournement : toujours lancer `docker compose exec -e APP_ENV=test backend php bin/phpunit` en local (la CI n'est pas affectée - voir point 9, elle tourne dans une image générique sans cet `APP_ENV` réel positionné). Détail complet, et un second problème pré-existant et indépendant (collisions d'isolation de test) trouvés en même temps :
    [`docs/known-issues-backend-phpunit.md`](docs/known-issues-backend-phpunit.md).

29. **Un alias de modèle IA "toujours la dernière version" n'est pas fiable pour un pipeline de production - épingler une version exacte, comme pour toute autre dépendance.** Vérifié en direct pendant la conception de B1.5 : `gemini-flash-latest` a échoué de façon répétée avec de vraies erreurs `HTTP 503` ("surcharge") sur le document cible réel, alors que `gemini-3.5-flash` (une version explicitement épinglée) a fonctionné de façon fiable. `gemini-2.5-flash` (une version pourtant récente il y a peu) s'est révélée **totalement retirée** pour les nouvelles clés API (`HTTP 404`, message explicite redirigeant vers une version plus récente) - les modèles d'IA évoluent et disparaissent plus vite que les API REST classiques de ce projet.

30. **Envoyer un document PDF entier à un modèle d'IA quand seule une petite section compte augmente réellement le taux d'erreur, pas seulement le coût.** Vérifié en direct : un rapport complet de 96 pages (10+ Mo) a produit des échecs `503` répétés même après plusieurs tentatives avec délai ; la même extraction limitée aux 8 pages réellement utiles (une annexe) a fonctionné de façon fiable et complète (111/111 lignes exactes). Découper le PDF à la plage de pages pertinente avant l'envoi, pas après un premier échec.

31. **Un budget de timeout HTTP calibré sur un seul essai réussi peut se révéler insuffisant en conditions réelles répétées - et la boucle de nouvelle tentative doit capturer les vraies coupures réseau, pas seulement les erreurs applicatives.** Bug réel trouvé pendant la vérification bout-en-bout de B1.5 : un premier run réussi (hors Airflow) avait pris 168s, ce qui semblait valider un budget de 180s ; en conditions réelles via le conteneur `airflow`, deux tentatives consécutives ont échoué avec une vraie `httpx.ReadTimeout` à 180s puis 300s - une coupure réseau générique, pas une erreur `503` de Gemini. La boucle de nouvelle tentative ne capturait que `google.genai.errors.ServerError`, donc ce timeout crashait la tâche entière au lieu d'être réessayé. Corrigé en élargissant la capture à `httpx.TimeoutException`/`TransportError` et en portant le budget à 600s. Une seule mesure réussie ne suffit jamais à calibrer un budget de production - et une boucle de retry doit couvrir toute la famille d'erreurs transitoires réalistes, pas seulement celle observée en premier.

32. **Une même ligne source peut légitimement contribuer à deux catégories NEV à la fois plutôt qu'une seule.** Contrairement aux connecteurs précédents (une ligne source = un secteur NEV), la méthodologie du rapport OPEC Fund calcule séparément une part "adaptation" et une part "mitigation" (souvent toutes deux non nulles) pour un même projet réel - forcer un seul secteur aurait soit perdu de l'argent réel, soit mal attribué une partie du financement. Ce connecteur publie jusqu'à deux messages `nev.funding.raw` par ligne réelle du tableau source plutôt qu'un seul - à garder en tête pour toute future source dont la méthodologie ventile déjà les montants entre plusieurs dimensions.

33. **Un champ requis par une règle de classification en aval doit être transporté explicitement dans le message publié, pas recalculé plus tard.** Bug réel trouvé pendant l'auto-relecture du plan B1.5 (avant toute exécution) : une première ébauche appelait `map_opec_sector(sector_label, "")` dans `funding_validator.py`, avec une chaîne vide à la place du vrai nom du projet - ce qui aurait rendu la règle mot-clé "Energy + Wind/Solar/Hydro" totalement inopérante en production (tout projet hydroélectrique/éolien/solaire réel aurait été mis en quarantaine au lieu d'être classé en Renewable Energy). Corrigé en ajoutant un champ `project_name` au message publié, avec un test qui vérifie ce chemin de bout en bout (pas seulement la fonction de mapping appelée isolément) - confirmé en direct après correction : 3 lignes réelles, 110 M$, correctement classées en Renewable Energy.
34. **Un fichier de test qui importe un module de DAG hérite de toutes ses dépendances au niveau module - y compris `airflow` lui-même, qui n'est installé que dans l'image `airflow`, pas dans `funding-validator`.** Découverte réelle pendant le refactoring multi-tâches des DAGs (2026-08-31) : les premiers `pipeline/tests/test_dag_*_tasks.py` écrits pour tester les nouvelles fonctions `_extraire`/`_transformer`/`_publier` de chaque DAG ont échoué en collection avec `ModuleNotFoundError: No module named 'airflow'` dans `funding-validator` - cette image n'a jamais eu besoin d'Airflow pour son propre rôle de consommateur Kafka. Il a fallu router ces tests vers le conteneur `airflow` à la place, qui lui-même n'avait pas `pytest` installé (jamais eu besoin de faire tourner de tests avant que les DAGs n'aient leur propre logique testable). Résolu en ajoutant `pytest==8.3.3` à `_PIP_ADDITIONAL_REQUIREMENTS` du service `airflow`. Un projet à plusieurs images Docker n'a pas une seule "suite de tests" mais potentiellement plusieurs, une par image ayant les dépendances du code qu'elle teste.
35. **Un connecteur qui republie l'intégralité de son portefeuille à chaque exécution rend un validateur qui "additionne chaque message reçu" dangereux, pas seulement redondant.** Bug réel de production trouvé le 2026-08-31 en creusant B1.7 : chaque DAG de collecte (World Bank, GCF, AfDB) re-télécharge et republie tout son portefeuille courant à chaque run - pas seulement les nouveautés. `upsert_funding()` additionnait pourtant aveuglément chaque message reçu au total courant, sans jamais vérifier si le `project_id` avait déjà contribué lors d'un run précédent. Résultat vérifié en base réelle : Sénégal/Agriculture/1989 (Banque Mondiale) a été additionné 8 fois de suite avec exactement le même incrément (16,1M → 128,8M), et jusqu'à 92% des lignes `Funding` réelles de ces 3 sources étaient des versions historisées par ce bug, pas de vraies révisions. PNUE (sémantique remplacement, pas addition) et OPEC Fund PDF (protégé par son cache SHA-256 de document) n'étaient pas affectés. Corrigé par une vraie idempotence par projet (`funding_project_contribution`, décisions complètes : [`docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md`](docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md)) plutôt qu'un correctif superficiel - les données corrompues des 3 sources ont été supprimées et reconstruites à partir d'un run propre chacune : Sénégal/Agriculture/1989 affiche maintenant exactement 16 100 000 (au lieu de 128 800 000), et une vérification croisée confirme que la somme totale des lignes `Funding` courantes correspond exactement à la somme des contributions individuelles suivies par projet (268 138 642 869,00 des deux côtés pour la Banque Mondiale). Avant de faire sommer un validateur, toujours vérifier ce que fait réellement le collecteur en amont à chaque exécution - "récupère les nouveautés" et "récupère tout l'état courant" ont des implications d'idempotence radicalement différentes en aval.

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
| A2.3 | Export CSV/Excel : génération synchrone et asynchrone (au-delà de 500 lignes, avec notification), quotas par rôle | ✅ Fait |
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

**Phase A2 (A2.1 à A2.14) fonctionnellement complète.** Prochaine étape : Phase A3 (temps réel via Mercure, externalisation des secrets, rate limiting, tests automatisés, sauvegardes, mise en production HTTPS). Le Volet B (pipeline de données réelles) a démarré en parallèle : voir la section « Pipeline (Volet B) » plus bas. Détail complet, échéances et responsables : `Plan_Implementation_NEV_Climate_Data.xlsx`, onglet « Plan d'implémentation ».

**Documentation de conception disponible** pour tout ce qui est fait jusqu'ici (décisions prises, alternatives écartées, justifications) :
- [`docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md`](docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md) + [`docs/superpowers/plans/2026-08-22-a13-timescaledb-schema.md`](docs/superpowers/plans/2026-08-22-a13-timescaledb-schema.md)
- [`docs/superpowers/specs/2026-08-24-a14-jwt-authentication-design.md`](docs/superpowers/specs/2026-08-24-a14-jwt-authentication-design.md) + [`docs/superpowers/plans/2026-08-24-a14-jwt-authentication.md`](docs/superpowers/plans/2026-08-24-a14-jwt-authentication.md)

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

Deuxième connecteur du Volet B : collecte mensuelle des financements du Fonds Vert pour le
Climat, via l'API IATI Datastore (pas l'API/dashboard propre du GCF, injoignable au moment de
la conception — voir la spec). Décisions de conception complètes :
[`docs/superpowers/specs/2026-08-28-b12-gcf-connector-design.md`](docs/superpowers/specs/2026-08-28-b12-gcf-connector-design.md).

Nécessite `IATI_API_KEY` dans le `.env` racine (clé gratuite, inscription sur
`developer.iatistandard.org` → Subscriptions → "Exploratory").

```bash
docker compose exec airflow airflow dags trigger collecte_gcf
```

Réutilise l'infrastructure et le topic `nev.funding.raw` déjà provisionnés par B1.1 — aucun
nouveau service, aucun nouveau topic.

### Connecteur BAD (B1.3)

Troisième connecteur du Volet B : collecte trimestrielle des financements de la Banque
Africaine de Développement (Groupe BAD), via la même API IATI Datastore que B1.2. Premier
connecteur à effectuer une vraie conversion de devise (XDR→USD, la BCE ne pouvant
structurellement pas servir cette devise — voir la spec) et à peupler pour de vrai les colonnes
`originalAmount`/`originalCurrency`/`exchangeRate` de `Funding`, réservées depuis A1.3.
Décisions de conception complètes :
[`docs/superpowers/specs/2026-08-28-b13-afdb-connector-design.md`](docs/superpowers/specs/2026-08-28-b13-afdb-connector-design.md).

```bash
docker compose exec airflow airflow dags trigger collecte_afdb
```

Réutilise `IATI_API_KEY` (déjà configuré depuis B1.2) et l'infrastructure existante — aucun
nouveau service, aucun nouveau topic. Contrairement à B1.2, ce connecteur pagine tout le
portefeuille (~5600 activités) au lieu d'une seule requête — voir le point d'attention sur la
limite de débit de l'offre gratuite IATI ci-dessous.

### Connecteur PNUE (B1.4)

Quatrième connecteur du Volet B : collecte annuelle des émissions de CO2 par pays, via l'API SDG
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

### Extracteur PDF — OPEC Fund (B1.5)

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
