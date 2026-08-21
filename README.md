# NEV Climate Data

Plateforme de collecte, structuration et diffusion de données climatiques et de financement (Volet A : application ; Volet B : pipeline de données). Ce dépôt contient actuellement les **fondations** du Volet A : environnement Docker et squelette de l'API backend Symfony (Phase A1, tâches A1.1 et A1.2 du plan d'implémentation).

## Structure du dépôt

```
nev-climate-data/
├── backend/                 Application Symfony (API REST)
│   ├── src/
│   │   ├── Controller/      Contrôleurs HTTP (ex: HealthController)
│   │   ├── Entity/          Entités Doctrine (vide pour l'instant — Point 3 du plan)
│   │   └── Repository/      Repositories Doctrine
│   ├── config/               Configuration Symfony (routes, packages, bundles)
│   ├── migrations/           Migrations Doctrine (vide pour l'instant)
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
> Les entités métier, migrations et TimescaleDB seront ajoutées au Point 3 (A1.3) du plan.

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

> Note : le service `database` utilise pour l'instant l'image standard `postgres:16-alpine`. Elle sera remplacée par l'image `timescale/timescaledb:latest-pg16` (compatible protocole PostgreSQL) au Point 3 du plan (A1.3), sans modification des variables de connexion.

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

## Tests automatisés

```bash
docker compose exec backend php bin/phpunit
```

## Connexion à la base de données

La connexion Symfony → PostgreSQL est configurée via la variable `DATABASE_URL`, injectée automatiquement dans le conteneur `backend` par `docker-compose.yml` à partir des variables `POSTGRES_*` du fichier `.env`.

Pour vérifier manuellement la connexion :

```bash
docker compose exec backend php bin/console doctrine:query:sql "SELECT 1"
```

## Prochaine étape

Les fondations Docker et Symfony (Points 1 et 2) sont posées. La suite du plan (Point 3 — déploiement de TimescaleDB et schéma de données « pipeline-ready ») sera traitée séparément.
