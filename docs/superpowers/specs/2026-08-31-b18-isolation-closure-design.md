# B1.8 — Clôture : isolation Kafka/MinIO et architecture Bronze/Silver/Gold

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-31
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 6.5 (isolation stricte
NEV/CIMA)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(décisions 1, 6)

## Vérification en direct avant toute décision

**Isolation Kafka** - 6 topics réels sur le broker Kafka dédié à ce projet, tous préfixés `nev.`,
jamais partagés avec CIMA (infrastructure entièrement séparée depuis le provisioning B1.1) :
`nev.emissions.raw/.rejets/.valides`, `nev.funding.raw/.rejets/.valides`.

**Isolation MinIO** - un seul bucket `nev-climate-data`, sur une instance MinIO dédiée à ce projet
(ses propres identifiants `MINIO_ROOT_USER`/`MINIO_ROOT_PASSWORD`, son propre conteneur Docker),
jamais connectée à l'infrastructure CIMA. Vérifié via `bucket_exists()` et une inspection complète
du contenu réel du bucket.

**Bronze/Silver - réellement écrits**, vérifié en listant le contenu réel du bucket :

```
bronze/afdb/2026-08-31/raw.json
bronze/gcf/2026-08-31/raw.json
bronze/pnue/2026-08-31/raw.json
bronze/worldbank/2026-08-31/raw.json
bronze/opec-fund-climate-finance-2024/2026-08-31/<hash>.pdf
silver/afdb/2026-08-31/payloads.json
silver/gcf/2026-08-31/payloads.json
silver/pnue/2026-08-31/payloads.json
silver/worldbank/2026-08-31/payloads.json
```

Ceci n'existait pas quand B1.8 a été rédigé dans le roadmap (MinIO était provisionné mais
totalement vide) - c'est devenu réel grâce à B1.5 (stockage du PDF source) et au refactoring
multi-tâches des 5 DAGs du 2026-08-31 (étape bronze/silver de transit entre `extraire` et
`transformer`).

**Gold - jamais écrit dans MinIO.** Aucun objet sous un préfixe `gold/` n'existe.

## Décision — Gold = TimescaleDB, pas un préfixe MinIO séparé

La spec d'architecture partagée (2026-08-26) décrit déjà ce choix, de façon incohérente entre ses
deux diagrammes : celui du pipeline B1 liste "MinIO Bronze/Silver/Gold" comme un bloc générique
hérité du pattern CIMA, mais celui de GreenAccess (B2) est explicite et sans ambiguïté :
*"écriture directe TimescaleDB **(Gold)** + cache Redis"* - TimescaleDB est littéralement annoté
"(Gold)" dans l'architecture d'origine.

En pratique, sur les 7 tâches B1 déjà livrées, `funding`/`emission` (TimescaleDB) sont la seule
donnée finale, validée et interrogeable que l'API Symfony et le dashboard lisent - exactement le
rôle d'une couche Gold. Aucune tâche à venir (B1.9 : fiabilité Airflow/alerting ; B1.10 : recette
Phase B1 ; B2 : connecteur GreenAccess, dont le propre diagramme confirme ce choix) n'a de besoin
identifié d'un export Gold indépendant vers MinIO (partage à un tiers, outil BI externe,
archivage indépendant de la base).

**Décision** : ne pas construire d'écriture `gold/` dans MinIO sans besoin réel - cohérent avec la
discipline déjà appliquée dans tout ce projet (YAGNI, ne pas ajouter de machinerie spéculative).
Documenter explicitement que TimescaleDB est la couche Gold de ce projet, pour que ce soit une
décision assumée plutôt qu'un oubli. Si un vrai besoin d'export apparaît plus tard (ex. partage de
données à un partenaire), ce sera une tâche séparée et explicite.

## Ce qui reste à faire

Rien en code. Ce document et la mise à jour du README constituent la clôture de B1.8 - l'objectif
réel du roadmap ("isoler strictement les données NEV de celles de CIMA") est déjà satisfait et
vérifié en direct ; le livrable ("topics Kafka et buckets MinIO NEV dédiés, cloisonnés de CIMA")
existe déjà.

## Documentation

`README.md` gagne une sous-section documentant l'état vérifié de l'isolation et la décision
Gold = TimescaleDB.
