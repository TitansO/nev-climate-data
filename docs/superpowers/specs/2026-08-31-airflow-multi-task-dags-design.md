# Refactoring des 5 DAGs Volet B en tâches Airflow réelles et reliées

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-31
Plan reference: n/a — amélioration transverse demandée par Serge, hors numérotation B1.x du
`Plan_Implementation_NEV_Climate_Data.xlsx` (les 5 connecteurs concernés — B1.1 à B1.5 — sont
déjà tous livrés et vérifiés ; ce travail ne change aucun de leurs résultats fonctionnels)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(décision 6 — Bronze/Silver/Gold via MinIO — posée dès B1.1 mais jusqu'ici seulement mise en
œuvre réellement par B1.5)

## Origine et objectif

Serge a partagé 5 captures d'écran de la vue "Graph" d'Airflow (`extraction_pdf`,
`collecte_worldbank`, `collecte_pnue`, `collecte_gcf`, `collecte_afdb`) : chacune montre une
seule tâche isolée, sans lien de dépendance visible. Vérifié dans le code réel : c'est exact —
chaque DAG contient exactement un `PythonOperator` dont la fonction `_collect(**context)` fait
tout en un seul appel Python (récupération depuis la source, transformation, publication Kafka).
Le traitement "aval" (validation, écriture en base) n'est pas non plus une tâche Airflow : il
tourne en dehors d'Airflow, dans deux services Kafka permanents (`funding-validator`,
`emission-validator`) — une décision d'architecture assumée depuis B1.1, non remise en cause ici.

Serge a choisi explicitement l'option "découper chaque DAG en plusieurs tâches réelles" (et non
l'option d'intégrer les services de validation dans Airflow, qui reste hors périmètre).

**Objectif** : que la vue "Graph" de chacun des 5 DAGs affiche une vraie chaîne de tâches
dépendantes, **sans changer le résultat final** — mêmes messages, sur les mêmes topics Kafka,
avec le même contenu, consommés par les mêmes validateurs inchangés.

## Décision 1 — Découpage en 3 tâches : `extraire >> transformer >> publier`

Même schéma pour les 5 DAGs. Ce découpage correspond exactement aux frontières de fonctions
déjà présentes dans le code de chaque collecteur (`pipeline/collectors/*.py`) :
- `extraire` : les fonctions `fetch_*` existantes (appels réseau vers la source).
- `transformer` : les fonctions `parse_*`/`build_payloads` existantes (logique pure, déjà
  testées unitairement, inchangées).
- `publier` : `producer.send(...)` + `producer.flush()`.

Aucune logique métier n'est réécrite — elle est répartie dans 3 tâches au lieu d'une. Bénéfice
réel additionnel : aujourd'hui, un échec en cours de route (ex. `HTTP 429` sur la pagination
AfDB) fait tout rejouer depuis zéro via les 3 `retries` Airflow existants (re-fetch + re-parse +
re-publish). Avec ce découpage, un échec dans `transformer` ne rejoue que `transformer` — les
données déjà récupérées en bronze ne sont pas re-téléchargées, ce qui respecte mieux la vraie
limite de 1 req/s d'AfDB (`PAGINATION_DELAY_SECONDS`) en cas de nouvelle tentative.

## Décision 2 — Transport inter-tâches : MinIO (bronze/silver), pas les données brutes en XCom

Chaque tâche ne pousse en XCom qu'une petite métadonnée (chemin d'objet MinIO en texte, nombre
d'enregistrements, éventuellement un indicateur `cache_hit` pour `extraction_pdf`) — jamais les
enregistrements bruts ou transformés eux-mêmes.

- `extraire` écrit son résultat en JSON dans MinIO, préfixe `bronze/<connecteur>/<ds>/raw.json`
  (`<ds>` = date d'exécution Airflow, évite les collisions entre exécutions).
- `transformer` lit ce JSON, transforme, réécrit en JSON dans MinIO, préfixe
  `silver/<connecteur>/<ds>/payloads.json`.
- `publier` lit ce JSON et publie sur Kafka.

**Pourquoi** (confirmé avec Serge — voir échange de conception) : AfDB seul récupère 5 604
activités brutes par exécution ; World Bank et PNUE bouclent sur toute la liste des pays NEV et
peuvent cumuler plusieurs milliers d'enregistrements. XCom est stocké dans la base de métadonnées
d'Airflow elle-même et n'est pas prévu pour transporter des lots de données de cette taille
(recommandation Airflow elle-même). MinIO est déjà auto-hébergé et provisionné dans ce projet
depuis B1.1 (aucun coût supplémentaire, contrairement à l'API Gemini) — cette décision concrétise
enfin l'architecture Bronze/Silver/Gold pour l'ensemble des connecteurs, pas seulement B1.5.

## Décision 3 — Nouveau module partagé `pipeline/common/minio_staging.py`

Fonctions génériques réutilisées par les 5 DAGs :
- `make_minio_client() -> Minio`
- `upload_bytes(client, object_path: str, data: bytes) -> None`
- `download_bytes(client, object_path: str) -> bytes`
- `upload_json(client, object_path: str, data: Any) -> None`
- `download_json(client, object_path: str) -> Any`

`pipeline/common/pdf_extraction.py` (B1.5) est mis à jour pour importer `make_minio_client` et
réutiliser `upload_bytes` depuis ce nouveau module au lieu de définir ses propres
`make_minio_client()`/`upload_to_minio()` — un nettoyage justifié : ces deux fonctions n'ont
jamais eu de logique spécifique au PDF, et le deviennent réellement partagées avec ce travail.
Le comportement et la signature externes de `pdf_extraction.py` ne changent pas (mêmes noms de
fonctions exportées), donc `pipeline/tests/test_pdf_extraction.py` n'a besoin d'aucune
modification fonctionnelle — seuls les patches `unittest.mock` ciblant l'implémentation interne
(`pipeline.common.pdf_extraction.Minio` → `pipeline.common.minio_staging.Minio`) sont ajustés.

## Décision 4 — Détail par DAG

Tous les DAGs conservent : mêmes `dag_id`, mêmes `schedule_interval`, mêmes `tags`, mêmes
`default_args` (3 `retries`, 5 min de délai). Seule la structure interne change.

### `collecte_worldbank`
- `extraire` : requête `SELECT iso_code FROM country` + conversion alpha-3→alpha-2 (logique
  existante inchangée, déplacée telle quelle depuis `_collect`) + boucle
  `fetch_projects_for_country(iso)` par pays → liste de projets bruts → `bronze/worldbank/<ds>/raw.json`.
- `transformer` : lit le bronze, `parse_project(project)` sur chaque élément (ignore les `None`)
  → `silver/worldbank/<ds>/payloads.json`.
- `publier` : lit le silver, `producer.send("nev.funding.raw", payload)` + `flush()`,
  `xcom_push(key="published_count", ...)` (nom de clé conservé pour compatibilité).

### `collecte_gcf`
- `extraire` : `fetch_gcf_activities()` (un seul appel, portefeuille complet) → liste brute →
  `bronze/gcf/<ds>/raw.json`.
- `transformer` : `parse_activity(activity)` sur chaque élément (éclatement multi-pays déjà géré
  par la fonction existante) → `silver/gcf/<ds>/payloads.json`.
- `publier` : identique au schéma ci-dessus.

### `collecte_afdb`
- `extraire` : `fetch_xdr_to_usd_rate()` + `fetch_afdb_activities()` (pagination réelle,
  `PAGINATION_DELAY_SECONDS` respecté, inchangé) → `{"xdr_to_usd_rate": ..., "activities": [...]}`
  → `bronze/afdb/<ds>/raw.json`.
- `transformer` : `parse_activity(activity, xdr_to_usd_rate)` sur chaque activité →
  `silver/afdb/<ds>/payloads.json`.
- `publier` : identique au schéma ci-dessus.

### `collecte_pnue`
- `extraire` : requête `SELECT iso_code FROM country` (logique existante inchangée) + boucle
  `country_iso3_to_m49` + `fetch_emissions_for_country` par pays → liste d'éléments
  `{"country_iso": ..., "row": {...}}` (le `country_iso` doit voyager avec chaque ligne brute —
  c'est le correctif du vrai bug Angola de B1.4 : `parse_emission` a besoin du `country_iso`
  d'origine, pas d'un recalcul à partir de `geoAreaCode`) → `bronze/pnue/<ds>/raw.json`.
- `transformer` : `parse_emission(row, country_iso)` sur chaque élément →
  `silver/pnue/<ds>/payloads.json`.
- `publier` : `producer.send("nev.emissions.raw", payload)` + `flush()`, `xcom_push(key="published_count", ...)`.

### `extraction_pdf`
Seul DAG avec un court-circuit de cache — voir décision 5.

## Décision 5 — Court-circuit du cache pour `extraction_pdf`

Pas de branchement dynamique Airflow (`ShortCircuitOperator`, `BranchPythonOperator`) — complexité
non justifiée ici. À la place, un indicateur `cache_hit` (booléen) circule en XCom entre les 3
tâches ; chaque tâche suivante le lit et ne fait rien si vrai. Comportement final identique à
aujourd'hui : cache hit → 0 message publié, aucun appel Gemini, aucune écriture MinIO/BDD.

- `extraire` : télécharge le PDF, calcule le hash SHA-256, ouvre une connexion BDD et vérifie
  `is_already_processed(cursor, hash)`.
  - Si déjà traité : `xcom_push(cache_hit=True, document_hash=...)`. Rien d'autre.
  - Sinon : découpe les pages de l'Annexe 2 (`slice_pdf_pages`, inchangé), upload le PDF complet
    d'origine dans MinIO au même chemin qu'aujourd'hui
    (`bronze/opec-fund-climate-finance-2024/<date>/<hash>.pdf`), upload aussi les pages découpées
    (petites, ~50 Ko) à `bronze/opec-fund-climate-finance-2024/<date>/<hash>-annex.pdf`.
    `xcom_push(cache_hit=False, document_hash=..., minio_path_pdf=..., minio_path_annex=...)`.
- `transformer` :
  - Si `cache_hit` : `xcom_push(cache_hit=True, ...)` (propage l'état), rien d'autre.
  - Sinon : télécharge les pages découpées depuis MinIO, `extract_json_via_gemini(...)` (appel
    Gemini réel, inchangé — c'est la partie lente/coûteuse, elle appartient bien à `transformer`
    et non à `extraire`), parse le JSON, `build_payloads(row, document_hash)` sur chaque ligne →
    `silver/opec-fund-climate-finance-2024/<ds>/payloads.json`. `xcom_push(cache_hit=False,
    payloads_path=..., rows_extracted=len(rows), document_hash=..., minio_path_pdf=...)`.
- `publier` :
  - Si `cache_hit` : `xcom_push(key="published_count", value=0)`. Rien d'autre — comportement
    identique à aujourd'hui.
  - Sinon : lit le silver, publie chaque payload sur `nev.funding.raw` + `flush()`, puis ouvre une
    connexion BDD et appelle `record_processed(cursor, document_hash=..., source_name=...,
    source_url=..., minio_path=minio_path_pdf, rows_extracted=...)` — l'enregistrement du cache
    n'a lieu qu'après une publication réellement réussie, exactement comme aujourd'hui (le cache
    n'est jamais marqué "traité" avant que le résultat existe vraiment).
    `xcom_push(key="published_count", value=published)`.

## Décision 6 — Identifiants de tâches

`task_id` identiques dans les 5 DAGs : `extraire`, `transformer`, `publier`. Les `task_id` ne
sont uniques qu'au sein d'un DAG (pas globalement) en Airflow, donc aucune collision. Ce choix
est délibéré : Serge verra le même schéma à 3 nœuds reconnaissable dans les 5 graphes, ce qui
répond directement à sa question d'origine.

## Ce qui ne change pas

- Aucune tâche Airflow n'est ajoutée pour la validation/écriture en base — `funding-validator` et
  `emission-validator` restent des services Kafka permanents, hors Airflow (option explicitement
  écartée par Serge).
- Aucun topic Kafka, aucune clé de payload, aucune règle de déduplication ne change.
- Les fonctions `fetch_*`/`parse_*`/`build_payloads` de chaque collecteur ne changent pas de
  signature ni de comportement — elles sont seulement appelées depuis un endroit différent (le
  fichier DAG plutôt qu'un unique `_collect`).
- `funding_validator.py` / `emission_validator.py` : aucune modification.

## Testing approach

- Nouveau `pipeline/tests/test_minio_staging.py` : tests unitaires (mocks) pour
  `upload_bytes`/`download_bytes`/`upload_json`/`download_json`, même style que les tests MinIO
  existants de B1.5.
- `pipeline/tests/test_pdf_extraction.py` : ajustement des patches de mock vers le nouveau module
  (décision 3), sans changement de comportement testé.
- Un nouveau fichier de test par DAG (ex. `pipeline/tests/test_dag_worldbank_tasks.py`) exerçant
  les 3 fonctions de tâche (`_extraire`, `_transformer`, `_publier`) avec BDD/Kafka/MinIO mockés —
  c'est de la nouvelle logique d'orchestration (lecture/écriture MinIO, propagation XCom) qui
  n'existait pas avant ce travail et mérite sa propre couverture, même si la logique métier
  qu'elle appelle est déjà testée ailleurs.
- Test spécifique au court-circuit de cache de `extraction_pdf` (décision 5) : `cache_hit=True`
  propagé par `extraire` doit aboutir à `published_count=0` sans qu'aucun mock Gemini/MinIO/Kafka
  ne soit appelé par `transformer` ou `publier`.
- Suite offline complète (`docker compose run --rm funding-validator pytest ...`) doit rester
  verte après ce travail — aucune régression attendue sur les tests déjà existants des 5
  collecteurs.
- Vérification end-to-end réelle après implémentation : déclencher chacun des 5 DAGs
  (`airflow dags trigger ...`), confirmer dans l'UI Airflow que le graphe affiche bien 3 tâches
  reliées, et confirmer que le nombre de messages publiés sur chaque topic est identique à une
  exécution de référence d'avant refactoring (même compte, mêmes données).

## Documentation

`README.md` gagne une note dans la section "Pipeline (Volet B)" documentant ce refactoring : le
passage de 1 à 3 tâches par DAG, le rôle de MinIO comme zone de transit bronze/silver entre
tâches (et non plus seulement de stockage permanent côté B1.5), et le fait que le court-circuit
de cache d'`extraction_pdf` est géré par un indicateur XCom plutôt qu'un branchement Airflow
dynamique.
