# NEV Climate Data

Plateforme de collecte, structuration et diffusion de données climatiques et de financement (Volet A : application ; Volet B : pipeline de données). Ce dépôt contient actuellement les **fondations** du Volet A : environnement Docker, squelette de l'API backend Symfony, et le schéma de données TimescaleDB « pipeline-ready » (Phase A1, tâches A1.1, A1.2 et A1.3 du plan d'implémentation).

## Structure du dépôt

```
nev-climate-data/
├── backend/                 Application Symfony (API REST)
│   ├── src/
│   │   ├── Controller/      Contrôleurs HTTP (ex: HealthController)
│   │   ├── Entity/          Entités Doctrine (Country, Sector, Source, User, Funding, Report, ApiKey, Notification)
│   │   └── Repository/      Repositories Doctrine
│   ├── config/               Configuration Symfony (routes, packages, bundles)
│   ├── migrations/           Migrations Doctrine (2 migrations : couche 1 puis couche 2 — voir « Schéma de données »)
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

Le schéma est réparti en deux migrations Doctrine, correspondant à deux couches de dépendances :

**Couche 1 — sans clé étrangère** : `Country`, `Sector`, `Source`, `User` (table `users`, car `user` est un mot réservé en PostgreSQL).

**Couche 2 — dépend de la couche 1** : `Funding` (clés étrangères vers `Country`, `Sector`, `Source`), `Report` (clé étrangère optionnelle vers `Country`), `ApiKey` et `Notification` (clé étrangère vers `User`).

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

## Prochaine étape

Les fondations Docker et Symfony (Points 1 et 2) sont posées, TimescaleDB et le schéma « pipeline-ready » (Point 3 — A1.3) sont en place, et l'authentification JWT (Point 4 — A1.4) est opérationnelle : voir la section « Authentification » ci-dessus. La suite du plan (Phase A2 — fonctionnalités et intégration frontend) sera traitée séparément.
