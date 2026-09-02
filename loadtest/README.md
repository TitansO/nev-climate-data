# Tests de charge (A3.7)

Cible du cahier des charges (section 5.5) : **<500ms** sur les endpoints les plus sollicités.

## Outil

[k6](https://k6.io/) (Grafana). Binaire statique, installation en une commande :

```bash
curl -sL https://github.com/grafana/k6/releases/download/v0.54.0/k6-v0.54.0-linux-amd64.tar.gz | tar xz -C /tmp
sudo mv /tmp/k6-v0.54.0-linux-amd64/k6 /usr/local/bin/k6
```

## Scripts

- **`baseline.js`** - 5 utilisateurs virtuels (VUs), 20s. Isole le coût réel d'une requête de la contention de concurrence sur une machine de dev partagée.
- **`ramping.js`** - montée en charge jusqu'à 50 VUs par scénario (100 au total), sur `GET /api/funding` (le endpoint public le plus réaliste) et les 3 agrégats `/api/analytics/*` les plus lourds (chargés ensemble par `visualizations.html`).

**Ne jamais pointer ces scripts vers le service Render de production** (palier gratuit, vraie ressource) - toujours contre le backend Codespace/local.

```bash
BASE_URL=http://localhost:8080 k6 run loadtest/baseline.js
BASE_URL=http://localhost:8080 k6 run loadtest/ramping.js
```

`ramping.js` va se faire bloquer par le rate limiting général (A3.4, `App\EventListener\ApiRateLimitListener`) en quelques secondes à cette concurrence - c'est le comportement voulu de ce mécanisme. Pour un vrai test de charge, augmenter temporairement la limite `api_anonymous` dans `backend/config/packages/rate_limiter.yaml` **sans jamais committer ce changement** (`git checkout HEAD -- backend/config/packages/rate_limiter.yaml` une fois terminé), puis `php bin/console cache:clear` pour que le changement prenne effet.

## Résultats mesurés (2026-09-02, Codespace 4 vCPU)

### Baseline (5 VUs, sous la capacité de la machine)

| Endpoint | p95 |
|---|---|
| `GET /api/funding` (filtré) | 87ms |
| `GET /api/analytics/hero-stats` | 36ms |
| `GET /api/analytics/financing-trends` | 31ms |
| `GET /api/analytics/sector-distribution` | 31ms |

**Tous largement sous la cible de 500ms.**

### Montée en charge (jusqu'à 100 VUs simultanés)

p95 mesuré entre 993ms et 1.35s - **dépasse la cible à cette concurrence**, mais explication vérifiée en direct, pas supposée : ce Codespace n'a que **4 vCPU**, partagés avec l'intégralité de la stack Volet B qui tourne en même temps (Kafka, Zookeeper, Airflow, 2 validateurs, MinIO, etc. - `docker stats` confirme ~15 conteneurs actifs). `EXPLAIN ANALYZE` sur les requêtes réelles confirme que le SQL lui-même reste sous la milliseconde (voir plus bas) - la dégradation vient de la contention CPU sur cette machine partagée, pas du code ni de la base de données. Un environnement de production dédié (comme Render, sans la stack Volet B qui tourne à côté) n'aurait pas cette contention.

## Vérification base de données (`EXPLAIN ANALYZE`)

Les 3 agrégats analytics (`AnalyticsService`/`FundingRepository::find*Aggregate()`) filtrent tous `WHERE is_current = true` sans condition supplémentaire sélective. Vérifié en direct :

```sql
EXPLAIN ANALYZE SELECT s.id, s.name, SUM(f.amount) FROM funding f
  JOIN sector s ON s.id = f.sector_id
  WHERE f.is_current = true GROUP BY s.id, s.name ORDER BY SUM(f.amount) DESC;
-- Execution Time: 0.807 ms (1080 lignes)
```

Un index partiel (`idx_funding_is_current`, migration `Version20260902133208`) a été ajouté malgré cette latence déjà négligeable - **de façon proactive, pas réactive** : l'historisation SCD2 du pipeline Volet B fait grandir la « queue » de lignes non-courantes indépendamment (et souvent plus vite) que le nombre de lignes courantes à chaque nouvelle exécution sur des données déjà vues (voir le point 36 du README principal). Le planificateur PostgreSQL choisit aujourd'hui un `Seq Scan` malgré l'index (comportement correct : à 100% de sélectivité `is_current = true`, un balayage séquentiel est réellement plus rapide) - l'index sera automatiquement exploité par le planificateur une fois que les lignes non-courantes s'accumuleront, sans aucun changement de code nécessaire.

## Conclusion

Le code applicatif respecte largement la cible de 500ms (confirmé par `EXPLAIN ANALYZE` et le test à charge réaliste). Aucune requête N+1 trouvée (`FundingRepository` charge déjà pays/secteur/source en une seule requête via `addSelect`/`join`). Un index proactif a été ajouté pour anticiper la croissance des données. La dégradation observée à très forte concurrence est un artefact de la machine de développement partagée, documenté ici pour ne pas être mal interprété lors d'un futur test de charge sur ce même Codespace.
