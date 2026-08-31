# B1.6 — Clôture du processor de validation et normalisation

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-31
Plan reference: B1.6 (Phase B1 — Connecteurs sources officielles),
`Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 6.4 (règles de gouvernance
de la donnée : devise pivot, déduplication, absence ≠ zéro)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(décisions 3, 7, 8, 9)

## Contexte — pourquoi ce n'est pas un nouveau développement

B1.6 demande un "processor de validation et normalisation (devise pivot, upsert, valeurs
manquantes)" appliquant les règles de gouvernance de la section 6.4. Vérification du code réel
(`pipeline/processors/funding_validator.py`, `pipeline/processors/emission_validator.py`) avant
toute écriture de code : **l'essentiel de ce livrable existe déjà**, construit progressivement
comme infrastructure partagée depuis B1.1, pas comme tâche isolée. Ce document formalise cet état
et corrige le seul vrai manque trouvé en le vérifiant.

## Ce qui satisfait déjà B1.6, avec preuves

1. **"Processor de validation opérationnel, consumer Kafka → TimescaleDB"** — `funding-validator`
   et `emission-validator` sont deux services Kafka permanents (voir `docker-compose.yml`),
   actifs depuis B1.1, qui consomment `nev.funding.raw`/`nev.emissions.raw` et écrivent
   directement dans TimescaleDB (`funding_validator.py::run()`, `emission_validator.py::run()`).

2. **"Déduplication" / "upsert"** — clé de dédoublonnage `(source_id, country_id, sector_id,
   year, funding_type)` pour `Funding`, `(source_id, country_id, year)` pour `Emission` (spec
   architecture décision 7), implémentée par `upsert_funding()`/`upsert_emission()` : recherche
   de la ligne courante, clôture (`is_current = false`, `valid_to = now()`), insertion d'une
   nouvelle ligne historisée. Historisation SCD2 réelle (décision 8), jamais d'écrasement en
   place.

3. **"Absence ≠ zéro"** — vérifié pour les 5 connecteurs : chaque `parse_*`/`build_payloads`
   retourne `None`/liste vide quand la donnée source est absente (ex.
   `world_bank.py::parse_project` : `if not total_amount ... return None`), donc rien n'est
   jamais publié sur Kafka pour une donnée manquante - l'absence reste une absence (aucune ligne),
   jamais une ligne à valeur zéro. Cette règle est appliquée à la source (collecteurs), pas dans
   le processor lui-même - c'est le bon endroit : le processor n'a jamais à décider si un montant
   représenté est "vraiment zéro" ou "manquant", cette ambiguïté est résolue avant qu'un message
   n'existe.

4. **"Conversion en devise pivot"** — satisfaite en résultat (toutes les données `Funding` sont en
   USD) mais **pas par le mécanisme centralisé initialement prévu**. La spec d'architecture
   (décision 9) envisageait un processor convertissant via les taux quotidiens BCE. En pratique,
   vérifié en direct pour chaque connecteur : World Bank (`totalamt` déjà en USD à la source) et
   OPEC Fund PDF (US$MN déjà en USD dans le tableau source) n'ont jamais eu besoin de conversion ;
   AfDB (seul cas réel de devise étrangère, XDR) convertit **dans le collecteur lui-même**
   (`pipeline/collectors/afdb.py::fetch_xdr_to_usd_rate`), parce que la BCE ne cote
   structurellement pas le XDR (déviation documentée et approuvée dans la spec B1.3, décision 4).
   **Décision de ce document** : ne pas construire a posteriori une étape de conversion BCE
   générique dans le processor pour un besoin qui ne s'est matérialisé nulle part - ce serait de
   la machinerie spéculative, contraire à la discipline déjà appliquée dans tout ce projet (YAGNI,
   vérifier contre de vraies données avant de généraliser). Documenté explicitement ici plutôt que
   silencieusement, pour que cette divergence soit une décision assumée et pas un oubli.

## Le vrai manque trouvé, et son correctif

Aucun des deux processors n'entoure son appel à `process_message()` d'un `try`/`except` dans
`run()`. Un message malformé ou inattendu (champ manquant, type incompatible, erreur DB non
prévue) lève une exception qui traverse tout `run()` et **arrête le service permanent entier** -
au lieu d'être mis en quarantaine sur `nev.funding.rejets`/`nev.emissions.rejets` comme tous les
autres rejets. C'est une vraie violation de l'esprit de la règle 6.4 ("un enregistrement rejeté
n'est jamais silencieusement perdu") : ici, ce n'est pas l'enregistrement qui serait perdu, c'est
le service tout entier qui s'arrêterait de traiter tous les messages suivants, sans qu'aucune
trace n'indique lequel a causé l'arrêt.

**Correctif** : dans `run()` (les deux fichiers), entourer le bloc `with connection: ...` d'un
`try`/`except Exception`. Sur une exception inattendue, `accepted, reason = False,
f"processing_error:{type(exc).__name__}"` - le message est alors publié sur le topic `.rejets`
comme n'importe quel autre rejet, avec une raison indiquant qu'il s'agit d'une erreur de
traitement plutôt qu'un rejet de gouvernance normal (secteur/pays inconnu). Le rollback de la
transaction PostgreSQL déjà en cours (`with connection:` de psycopg2 fait un `rollback()`
automatique sur exception avant de la relaisser remonter) continue de fonctionner tel quel - le
nouveau `try`/`except` se contente de capturer l'exception une fois qu'elle ressort de ce bloc,
sans changer son comportement de rollback. Un message d'erreur est aussi imprimé (`print(...)`,
aucune convention de logging n'existe encore ailleurs dans `pipeline/`) pour rester visible via
`docker compose logs funding-validator`/`emission-validator`.

## Testing approach

Nouveaux tests (un par fichier) qui simulent un message dont le traitement lève une exception
inattendue (ex. `process_message` mocké avec `side_effect=KeyError("amount_usd")`) et vérifient :
la boucle `run()` ne lève pas, le message est publié sur le topic `.rejets` avec
`rejection_reason` commençant par `"processing_error:"`, et la consommation continue pour le
message suivant (deuxième message factice traité normalement après le premier en erreur). Ces
tests mockent `make_consumer`/`make_producer`/`get_connection` - `run()` n'a jamais été testé
directement jusqu'ici (seul `process_message()` l'était, contre une vraie transaction DB
annulée en fin de test).

## Documentation

`README.md` gagne une nouvelle sous-section "Processor de validation et normalisation (B1.6)"
dans "Pipeline (Volet B)", qui renvoie vers ce document et résume le constat : l'essentiel déjà
livré avec B1.1-B1.5, un vrai manque de robustesse trouvé et corrigé.
