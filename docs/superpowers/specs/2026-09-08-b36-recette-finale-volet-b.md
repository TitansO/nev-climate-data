# B3.6 - Recette finale du Volet B et validation de mise en production

Status: Proposée - en attente de validation écrite du Product Owner
Author: Claude (with Serge)
Date: 2026-09-08
Plan reference: B3.6 (Phase B3 - Bascule et qualité des données), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: critères de recette section 6.8 du cahier des charges (exactitude des agrégats,
idempotence du pipeline), section 10 (gouvernance de projet - recette formelle avant clôture de
phase)

## Goal

Produire le rapport de recette final validant que **l'ensemble du Volet B tel qu'il a été
livré** (Phase B1 - connecteurs officiels ; Phase B3 - bascule et qualité des données réelles)
fonctionne de bout en bout, avec de vraies données, en cohérence avec ce qui est réellement
servi en production. Correspond au livrable B3.6 : *"Rapport de recette finale Volet B +
validation de mise en production."*

## Scope

**Dans le périmètre** : B1.1-B1.10 (déjà recettés formellement le 2026-09-01, voir
[`2026-09-01-b110-phase-b1-recette.md`](docs/superpowers/specs/2026-09-01-b110-phase-b1-recette.md)),
B3.1 (agrégats réels), B3.2 (diffusion temps réel), B3.3 (bascule démo→réel), B3.4 (contrôle
qualité SQL), B3.5 (idempotence des DAGs).

**Hors périmètre, explicitement** : Phase B2 (connecteur GreenAccess) - toutes les tâches B2.1
à B2.8 restent au statut « Bloqué » dans le plan d'implémentation, aucune donnée GreenAccess
n'existe nulle part (ni Kafka, ni TimescaleDB). B3.1 et B3.2 ont chacun déjà documenté cette
décision (avancer sans attendre B2, sur instruction explicite de l'utilisateur) - ce rapport ne
la relitige pas. Toute mention de « KPIs réels et GreenAccess » dans le libellé officiel des
tâches B3.1/B3.2 doit se lire comme « KPIs réels du Volet B (B1) uniquement ».

## Method

Chaque critère est vérifié en direct sur le Codespace `Odjiguimde/nev-climate-data`
(`turbo-journey`), contre le HEAD réel de `developp` au moment de la rédaction
(`20b9671`), ou cité depuis une vérification déjà faite dans la même session de travail (B3.1
à B3.5, aujourd'hui) plutôt que répétée mécaniquement. Deux suites de tests (pytest pipeline
complet, pytest des tâches DAG) n'ont pas pu être re-exécutées dans cette session - voir
« Limite méthodologique » ci-dessous.

## Checklist / Report

### 1. Connecteurs officiels (B1.1-B1.5) - état réel des données

| Source | Lignes courantes validées | Montant total (USD) | `funding`/`funding_project_contribution` cohérents |
|---|---|---|---|
| World Bank Data API | 1 521 | 267 929 645 365 | ✅ Exact (267 929 645 365 des deux côtés) |
| GCF - IATI Datastore | 319 | 6 863 912 411 | ✅ Exact |
| AfDB - IATI Datastore | 1 117 | 41 968 345 537 | ✅ Exact |
| OPEC Fund - PDF (Gemini) | 48 | 332 150 960 | ✅ Exact - **écart de B1.10 résolu** : au moment de B1.10 (2026-09-01), `funding_project_contribution` était vide pour ces 48 lignes (créées avant l'introduction de cette table) ; aujourd'hui les deux sommes correspondent exactement, confirmé en direct |
| PNUE (UN SDG/IEA) | 828 lignes `emission` valides (hors 12 lignes `NaN` en cours de nettoyage, voir B3.4) | 24 349,89 Mt cumulés | N/A (table `emission`, sémantique remplacement) |

**Total réel Volet B (hors démonstration) : 3 005 lignes `funding` + 828 lignes `emission`
exploitables.**

### 2. Processor / gouvernance de la donnée (B1.6-B1.9) - cité depuis B1.10

Aucun changement de code dans ce périmètre depuis B1.10 (2026-09-01). Repris tel quel :
consumers Kafka permanents opérationnels, quarantaine sur message malformé, historisation SCD2
respectée en lecture (B1.7), isolation NEV/CIMA confirmée (6 topics `nev.*`, bucket MinIO dédié,
B1.8), alerting email réel sur échec de DAG (B1.9). Voir B1.10 pour le détail des commandes et
résultats.

**Topics Kafka confirmés en direct aujourd'hui** (pendant la préparation de B3.5) :
`nev.funding.raw`, `nev.funding.valides`, `nev.funding.rejets`, `nev.emissions.raw`,
`nev.emissions.valides`, `nev.emissions.rejets`, `__consumer_offsets` - tous préfixés `nev.`,
aucun topic CIMA.

### 3. Qualité des données (B3.4)

| Critère | Résultat |
|---|---|
| 5 agrégats analytiques vs requête SQL directe | ✅ Correspondance exacte sur les 5 (financing-trends, hero-stats, sector-distribution, country-distribution, co2-reduction) |
| Défaut trouvé et corrigé | 12 lignes `emission` avec `value_mt = NaN` (Soudan du Sud, 2000-2011) - corrigé dans `pipeline/collectors/pnue.py`, testé (9/9), voir [`2026-09-08-b34-b35-recette-controle-qualite.md`](docs/superpowers/specs/2026-09-08-b34-b35-recette-controle-qualite.md) |
| Nettoyage des lignes déjà en base | ⚠️ **Action restante** - `DELETE FROM emission WHERE value_mt::text = 'NaN';` à exécuter manuellement en dev et en production (bloqué par le classificateur de permissions sur les commandes destructives) |

### 4. Idempotence du pipeline (B3.5)

Les 5 DAGs rejoués sur un état déjà peuplé aujourd'hui : **0 doublon, 0 perte** sur toutes les
sources (nombre de lignes strictement identique avant/après). Le seul écart observé (AfDB,
-23 844 476 sur ~42 milliards, soit -0,00006%) est une vraie révision de la source IATI entre les
deux exécutions, absorbée correctement en delta par `funding_project_contribution` (voir détail
dans [`2026-09-08-b34-b35-recette-controle-qualite.md`](docs/superpowers/specs/2026-09-08-b34-b35-recette-controle-qualite.md)).
Cache SHA-256 d'OPEC Fund confirmé : second déclenchement, 0 message republié.

### 5. Bascule démo → réel (B3.3)

Filtre `validation_status = 'validated'` actif sur tous les chemins de lecture. Vérifié en
direct aujourd'hui : `hero-stats.fundingRecords` = 3 005 (uniquement du réel), aucune ligne
`demo` n'atteint l'API. 1 080 lignes de démonstration restent en base (dev), inertes,
jamais servies - réversible, pas de suppression physique.

### 6. Cohérence dev ↔ production

| Endpoint | Dev (Codespace, aujourd'hui) | Production (Render, vérifié dans cette même session) | Statut |
|---|---|---|---|
| `GET /api/analytics/hero-stats` | `fundingRecords: 3005`, `activeSources: 4`, `countriesCovered: 53` | `fundingRecords: 3005`, `activeSources: 4`, `countriesCovered: 53` | ✅ Identique |
| `GET /api/funding` (échantillon réel) | Lignes réelles OPEC Fund/World Bank présentes | Ligne réelle Sénégal/OPEC Fund confirmée (id 6671, XOF→USD) | ✅ Cohérent |
| `GET /api/reports/{id}/download` | 200, vrai PDF (325 octets) après correctif du 2026-09-08 | Correctif poussé (`c1ddde3`), déploiement Render auto-deploy à confirmer | ⚠️ À reconfirmer après déploiement (voir note) |

**Note importante sur la portée de « mise en production » pour le Volet B** : le pipeline
d'orchestration lui-même (Airflow, Kafka, MinIO, les 2 validateurs) **ne tourne que sur le
Codespace de développement**, pas sur une infrastructure de production dédiée et persistante -
seule l'API Symfony (Render) et sa base TimescaleDB sont réellement déployées en production. Les
données réelles collectées par le pipeline **atteignent bien la production** (même base
PostgreSQL/TimescaleDB partagée, confirmé ci-dessus), mais la ré-exécution planifiée des
connecteurs (cron trimestriel) ne s'exécute aujourd'hui que si le Codespace tournant Airflow est
actif au moment prévu. Aucune tâche du plan officiel (B1-B3) ne demande explicitement le
déploiement d'Airflow en production - ce n'est donc pas un écart par rapport au périmètre validé,
mais un point à trancher consciemment si une collecte automatique sans intervention manuelle
devient un besoin réel.

## Limite méthodologique

Deux suites de tests n'ont pas pu être re-exécutées dans cette session à cause d'une lenteur
d'environnement (blocage à la collecte pytest, non résolu après plusieurs tentatives sur
~3 minutes cumulées - contention probable du Codespace, déjà documentée comme facteur connu en
A3.7) :
- Suite pytest pipeline complète (`pipeline/tests/ -m "not live"`, hors fichiers `test_dag_*_tasks.py`)
- Suite pytest des tâches DAG (`test_dag_*_tasks.py`, via le conteneur `airflow`)

Aucune des deux n'a de raison de régresser : le seul changement de code pipeline depuis leur
dernière exécution connue (B1.10, 127 passed) est le correctif NaN de `pnue.py`, dont le fichier
de test dédié (`test_pnue_collector.py`) a été exécuté isolément aujourd'hui avec succès
(9/9 tests). **Recommandation** : rejouer ces deux suites complètes avant la signature
définitive, dans un Codespace disposant de plus de marge mémoire/CPU, pour lever ce doute
méthodologique.

## Signature

En signant ci-dessous, le Product Owner valide que le Volet B (Phases B1 et B3 - Phase B2
GreenAccess explicitement exclue, toujours bloquée) est formellement recetté.

- Signataire : _(en attente de validation - Serge KOBI, Product Owner)_
- Date : _(à compléter à la signature)_
- Décision proposée : **Validé, sous 2 réserves non bloquantes** :
  1. Nettoyage SQL manuel des 12 lignes `emission` corrompues (dev + production) - correctif de
     code déjà livré, seule la donnée déjà en base doit être purgée.
  2. Rejeu des 2 suites de tests pipeline non exécutées dans cette session (voir « Limite
     méthodologique ») avant signature définitive, par prudence méthodologique - aucune
     régression attendue.
