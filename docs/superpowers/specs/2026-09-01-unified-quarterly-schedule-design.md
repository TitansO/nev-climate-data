# Fréquence unifiée trimestrielle pour tous les connecteurs Volet B

Status: Approved
Author: Serge (with Claude)
Date: 2026-09-01

## Décision

Sur demande explicite de Serge : **tous les DAGs de collecte du Volet B passent à une fréquence
trimestrielle uniforme** (`0 3 1 1,4,7,10 *` - 1er jour de chaque trimestre, 03h00), quelle que
soit leur fréquence d'origine. Cette règle s'applique aussi à **tout futur connecteur** (B2 et
au-delà) - une décision de gouvernance de projet, pas une déviation ponctuelle par connecteur.

## État avant/après

| DAG | Avant | Après |
|---|---|---|
| `collecte_worldbank` | trimestriel | trimestriel (inchangé) |
| `collecte_gcf` | mensuel | **trimestriel** |
| `collecte_afdb` | trimestriel | trimestriel (inchangé) |
| `collecte_pnue` | annuel | **trimestriel** |
| `extraction_pdf` | annuel | **trimestriel** |

## Ce qui change dans le code

Les 3 DAGs concernés (`collecte_gcf.py`, `collecte_pnue.py`, `extraction_pdf.py`) : la valeur
`schedule_interval` et le commentaire associé, ainsi que la première phrase de leur docstring qui
décrivait l'ancienne fréquence et sa justification (B1.4 décision 12, B1.5 décision 12 - ces
justifications restent valides comme contexte historique dans les specs déjà commitées, mais le
DAG lui-même reflète désormais la nouvelle politique de Serge, qui prévaut).

Aucun changement à la logique de collecte elle-même (`extraire`/`transformer`/`publier`,
idempotence, cache) - uniquement la cadence de déclenchement.

## Documentation

`README.md` (sections des connecteurs GCF et PNUE) et `docs/roadmap-volet-b.html` (livrables
B1.2/B1.4, qui mentionnaient encore "DAG Airflow mensuel"/"annuel") mis à jour pour refléter la
nouvelle fréquence.
