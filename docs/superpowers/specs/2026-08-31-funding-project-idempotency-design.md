# Correction du double comptage Funding — idempotence par projet

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-31
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 6.4 (déduplication,
"aucune donnée n'est écrasée", valeurs divergentes traçables)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(décisions 7-8), `pipeline/processors/funding_validator.py`

## Le bug, avec preuves

Chaque DAG de collecte (`collecte_worldbank`, `collecte_gcf`, `collecte_afdb`) re-télécharge et
republie l'**intégralité** de son portefeuille courant à chaque exécution, pas un delta. Or
`upsert_funding()` **additionne** le montant de chaque message reçu à la ligne courante de la clé
`(source, pays, secteur, année, type)`, sans jamais vérifier si le `project_id` précis a déjà
contribué à une exécution précédente. Résultat : chaque nouveau déclenchement du DAG recompte les
mêmes projets comme si c'était un nouveau financement.

Vérifié en base réelle (2026-08-31) : Sénégal/Agriculture/1989 (Banque Mondiale) a été additionné
**8 fois de suite avec exactement le même incrément** de 16 100 000 (16,1M → 32,2M → ... →
128,8M actuel) - la signature exacte d'un re-comptage, pas d'une évolution réelle des données.
Ampleur mesurée sur l'ensemble de la table `funding` :

| Source | Lignes historisées (remplacées) | Lignes totales |
|---|---|---|
| Banque Mondiale | 19 668 | 21 549 |
| BAD | 10 227 | 11 344 |
| GCF | 3 861 | 4 180 |
| OPEC Fund PDF | 4 | 52 |

PNUE (`emission_validator.py`) n'est pas affecté (sémantique **remplacement**, pas addition -
B1.4 décision 6). OPEC Fund PDF n'est pas affecté dans les faits : son cache par hash SHA-256
bloque toute re-publication d'un document déjà traité (0 message publié sur un re-run), donc le
chemin qui cause le bug ailleurs ne peut jamais se déclencher pour lui - les 4 lignes historisées
observées sont cohérentes avec un traitement normal, pas une corruption répétée.

## Décision 1 — Table de suivi par projet, upsert en delta (pas un simple "déjà vu = ignorer")

Nouvelle table `funding_project_contribution` : `id`, `source_id`, `project_id` (string),
`country_id`, `sector_id`, `year`, `funding_type`, `amount`, `updated_at`. **Clé unique
`(source_id, project_id, country_id)`** - le `country_id` fait partie de la clé à cause de B1.2 :
une même activité GCF (un seul `project_id`/`iati_identifier`) peut légitimement produire
plusieurs messages, un par pays bénéficiaire (split multi-pays, spec B1.2 décision 6). World Bank
et AfDB n'ont jamais plus d'un pays par projet, donc cette clé reste valide pour eux aussi (juste
redondante dans leur cas).

À chaque message entrant, `apply_project_contribution()` (nouvelle fonction dans
`funding_validator.py`) consulte la ligne de contribution existante pour
`(source_id, project_id, country_id)` :

1. **Aucune ligne existante** (vrai nouveau projet) → insertion de la ligne de contribution,
   application du montant complet comme delta sur la ligne `funding` agrégée. Comportement
   identique à aujourd'hui pour ce cas - c'est le seul cas qui n'était pas cassé.
2. **Ligne existante, même clé de dédoublonnage (secteur/année/type inchangés), montant
   identique** - **exactement le scénario du bug** (un re-run republie le même projet à
   l'identique) → `delta = 0`, **aucune écriture** sur `funding` (pas de nouvelle version
   historisée pour rien).
3. **Ligne existante, même clé, montant différent** (une vraie révision de la source) →
   `delta = nouveau_montant - ancien_montant` (peut être positif ou négatif), appliqué sur la
   ligne `funding` agrégée. La ligne de contribution est mise à jour avec le nouveau montant.
   C'est ce cas qui satisfait réellement "les valeurs divergentes entre sources restent
   traçables" - une vraie révision reste visible dans l'historique SCD2, contrairement à
   l'alternative plus simple envisagée (ignorer tout projet déjà vu) qui la perdrait
   silencieusement.
4. **Ligne existante, clé de dédoublonnage différente** (secteur/année/type ont changé - ex.
   correction du mapping sectoriel entre deux runs) → retrait de la contribution de l'**ancienne**
   ligne `funding` (`delta = -ancien_montant`) et ajout à la **nouvelle** (`delta =
   nouveau_montant`). Cas rare mais géré correctement plutôt que deviné.

`upsert_funding()` est généralisée : son paramètre `amount` devient `delta` (peut être négatif),
et un delta de zéro ne déclenche aucune écriture (garde explicite ajoutée). Sa logique de fond
(clôturer la ligne courante, insérer une nouvelle version historisée) ne change pas.

`process_message()` appelle désormais `apply_project_contribution()` au lieu d'appeler
`upsert_funding()` directement, pour les 4 sources `Funding` (`world_bank`, `gcf`, `afdb`,
`opec_fund_pdf`) - `project_id` existe déjà dans chaque message de ces 4 connecteurs mais n'était
utilisé par aucun code du validateur jusqu'ici.

## Décision 2 — Nouvelle entité `FundingProjectContribution` (Doctrine + migration)

Même précédent que `ProcessedDocument` (B1.5) : une table purement interne au pipeline Python,
créée via une entité Doctrine + migration parce qu'aucun autre outil de migration n'existe dans ce
projet, même si Symfony ne la lit ni ne l'écrit jamais. Colonnes : `id`, `sourceId`, `projectId`
(string), `countryId`, `sectorId`, `year`, `fundingType` (string), `amount` (decimal), `updatedAt`.
Contrainte unique `(source_id, project_id, country_id)`.

## Décision 3 — Correction des données déjà corrompues : vider et reconstruire, pas recalculer

Aucune contribution par projet n'a jamais été suivie avant ce correctif - il est donc impossible
de reconstruire algorithmiquement l'historique correct à partir des sommes déjà corrompues (on ne
sait pas, pour une ligne à 128,8M, combien de fois chaque projet individuel y a réellement
contribué). La correction :

1. **Supprimer entièrement** les lignes `funding` (courantes et historisées) des 3 sources
   confirmées corrompues - Banque Mondiale, GCF, BAD. **Pas** OPEC Fund PDF (données non
   corrompues, voir plus haut) ni les deux sources de démonstration Volet A (fixtures, jamais
   passées par le pipeline réel, 0 ligne historisée).
2. **Redéclencher une seule fois** chacun des 3 DAGs concernés avec le code corrigé - reconstruit
   les totaux corrects à partir du portefeuille réel actuel, et peuple `funding_project_contribution`
   pour la première fois, pour ces 3 sources.
3. **Vérifier** : Sénégal/Agriculture/1989 (Banque Mondiale) doit afficher exactement 16 100 000
   après reconstruction, pas 128 800 000.

**Ceci supprime des données réelles de la base - confirmation explicite de Serge requise avant
l'exécution de cette étape spécifique**, même si l'approche générale est déjà validée. Ce sera une
étape séparée et visible du plan d'implémentation, pas noyée dans le reste.

## Ce qui reste hors périmètre

- **PNUE (`emission_validator.py`)** : sémantique différente (remplacement, pas addition),
  fichier séparé, non affecté par ce bug. Note pour mémoire, non traitée ici : `upsert_emission()`
  crée aujourd'hui une nouvelle version historisée même si la valeur re-publiée est strictement
  identique à l'existante (pas une corruption de donnée - le montant reste juste, seulement du
  bruit d'audit inutile) - un vrai sujet, mais distinct de la corruption de `Funding` traitée ici,
  et non demandé par Serge.
- **Refonte des collecteurs pour ne publier que des deltas** (approche alternative envisagée puis
  écartée) : plus lourd, touche 3 connecteurs déjà testés et vérifiés en production, et ne résout
  pas mieux le problème que le corriger dans le validateur, seul endroit où le bug existe
  réellement.

## Testing approach

- Tests unitaires pour `apply_project_contribution()` (mock DB, comme les tests existants de
  `test_funding_validator.py`) couvrant les 4 cas de la décision 1 : nouveau projet, re-run
  identique (delta zéro, aucune écriture), révision réelle (delta appliqué), changement de clé de
  dédoublonnage (retrait + ajout).
- Test d'intégration contre une vraie transaction DB (même pattern `db_cursor` que l'existant) :
  publier deux fois le même message `world_bank` → un seul enregistrement `funding`, montant non
  doublé. Publier le même projet avec un montant différent → delta appliqué, pas le montant plein.
- Test de régression explicite reproduisant le bug réel trouvé : republier le message Sénégal/
  Agriculture/1989 huit fois de suite doit produire exactement le montant d'un seul run, pas huit
  fois ce montant.
- Vérification en direct après déploiement : requête SQL sur les totaux réels avant/après pour
  confirmer que le nombre de lignes historisées retombe à un niveau cohérent (un run = zéro ou une
  poignée de nouvelles versions, pas des milliers).

## Documentation

`README.md` gagne un nouveau point d'attention documentant ce bug réel (root cause, ampleur,
correctif) - même format que les points 21-34 existants. Nouvelle sous-section "Correction du
double comptage Funding (2026-08-31)" dans "Pipeline (Volet B)".
