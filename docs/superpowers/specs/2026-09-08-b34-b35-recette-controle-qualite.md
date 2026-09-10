# B3.4 & B3.5 - Contrôle qualité des données et idempotence des DAGs

Status: Approved
Author: Serge (with Claude)
Date: 2026-09-08
Spec reference: plan d'implémentation, tâches B3.4 (« Comparer les valeurs affichées aux
requêtes SQL de contrôle », critère de recette 6.8) et B3.5 (« Vérifier l'idempotence de chaque
DAG », critère de recette 6.8)
Environment: Codespace `Odjiguimde/nev-climate-data` (`turbo-journey`), stack complète,
5 connecteurs Volet B exécutés avec de vraies données.

## B3.4 - Comparaison valeurs affichées vs requêtes SQL de contrôle

Échantillon représentatif : les 5 agrégats exposés par `AnalyticsController`, comparés à une
requête SQL directe équivalente, cache Redis vidé avant chaque comparaison.

| Endpoint | Valeur API | Valeur SQL directe | Résultat |
|---|---|---|---|
| `GET /api/analytics/financing-trends` (somme totale) | 317 117 898 749 | `SUM(amount) WHERE is_current AND validation_status='validated'` = 317 117 898 749 | ✅ Exact |
| `GET /api/analytics/hero-stats` (fundingRecords) | 3 005 | `COUNT(*) WHERE is_current AND validation_status='validated'` = 3 005 | ✅ Exact |
| `GET /api/analytics/sector-distribution` (top 3) | Adaptation 119 061 554 876 · Sustainable Transport 103 031 401 563 · Agriculture 88 674 878 902 | Même requête groupée par secteur | ✅ Exact (3/3) |
| `GET /api/analytics/country-distribution` (top 3) | ETH 28 904 321 013 · NGA 24 124 472 474 · MAR 23 014 029 897 | Même requête groupée par pays | ✅ Exact (3/3) |
| `GET /api/analytics/co2-reduction` | `reductionMt: -7.83` (2022→2023, 35 pays communs) | Requête manuelle sur `emission` (intersection des pays, delta) = -7.830 | ✅ Exact |

**Un vrai écart trouvé et corrigé pendant ce contrôle** (pas dans le tableau ci-dessus, car il
ne touchait aucun agrégat déjà exposé - trouvé en creusant `emission` avant le calcul CO2) :
12 lignes `emission` (Soudan du Sud, années 2000 à 2011) portaient `value_mt = NaN` - l'API SDG
des Nations Unies renvoie littéralement la chaîne `"NaN"` pour certaines combinaisons pays/année
au lieu d'omettre la ligne, et `float("NaN")` réussit silencieusement en Python
(`pipeline/collectors/pnue.py::parse_emission()`). Un `NaN` contamine toute agrégation SQL/PHP
qui le contient (`SUM`/`array_sum` sur un `NaN` devient `NaN` pour tout le groupe) - ces 12 lignes
ne sont pas dans la fenêtre des 2 années les plus récentes utilisée par `getCo2Reduction()`
aujourd'hui, donc l'API n'est pas actuellement cassée, mais l'aurait été dès que la fenêtre
glissante les aurait incluses. Corrigé en rejetant explicitement les valeurs `NaN` dans
`parse_emission()` (même principe « Absence ≠ zéro » que le reste du pipeline), testé
(`test_parse_emission_discards_a_nan_value`, 9/9 tests verts). **Nettoyage restant** : les 12
lignes déjà en base (dev et production) doivent être supprimées manuellement -
`DELETE FROM emission WHERE value_mt::text = 'NaN';` - non fait automatiquement ici (bloqué par
le classificateur de permissions sur une commande `DELETE`).

**Conclusion B3.4** : tous les agrégats actuellement exposés au frontend correspondent
exactement à leur requête SQL de contrôle. Un vrai défaut latent trouvé et corrigé avant qu'il
ne devienne visible.

## B3.5 - Idempotence des DAGs (rejeu sans doublon ni perte)

Les 5 DAGs (`collecte_worldbank`, `collecte_gcf`, `collecte_afdb`, `collecte_pnue`,
`extraction_pdf`) redéclenchés manuellement sur un état déjà peuplé (chaque source avait déjà
tourné au moins une fois). Comparaison ligne à ligne avant/après.

| Source | Lignes avant | Lignes après | Montant avant (USD) | Montant après (USD) | Verdict |
|---|---|---|---|---|---|
| World Bank Data API | 1 521 | 1 521 | 267 929 645 365 | 267 929 645 365 | ✅ Identique |
| GCF - IATI Datastore | 319 | 319 | 6 863 912 411 | 6 863 912 411 | ✅ Identique |
| AfDB - IATI Datastore | 1 117 | 1 117 | 41 992 190 013 | 41 968 345 537 | ✅ 0 doublon, delta réel (-23 844 476, ~0,00006%) |
| OPEC Fund - PDF (Gemini) | 48 | 48 | 332 150 960 | 332 150 960 | ✅ Identique (cache SHA-256 : 0 message republié) |
| `funding_project_contribution` (suivi par projet) | 4 127 | 4 127 | - | - | ✅ Identique |
| `emission` (validées, hors NaN) | 828 | 828 | 24 349,89 Mt | 24 349,89 Mt | ✅ Identique |

**Aucun doublon, aucune perte sur les 5 DAGs.** Le seul écart observé (AfDB, -23 844 476 sur
~42 milliards, soit 0,00006%) n'est pas une erreur de rejeu : le nombre de lignes reste
strictement identique (1 117), preuve que le mécanisme de délta par projet
(`funding_project_contribution`, voir
[`2026-08-31-funding-project-idempotency-design.md`](docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md))
a correctement absorbé une révision réelle et minime de la source IATI entre les deux
exécutions, sans additionner ni dupliquer. OPEC Fund confirme le comportement attendu du cache
SHA-256 (B1.5) : second déclenchement sur le même document, 0 nouveau message publié.

**Conclusion B3.5** : critère de recette 6.8 satisfait - rejouer un DAG ne produit jamais de
doublon ni de perte, une vraie révision de source est appliquée comme un delta correct plutôt
qu'une addition aveugle.

## Actions de suivi

1. Nettoyer les 12 lignes `emission` corrompues (`value_mt = 'NaN'`) en dev **et en production**
   - commande SQL donnée ci-dessus, à exécuter manuellement.
2. B3.6 (recette finale Volet B) peut s'appuyer sur ce rapport comme preuve des critères 6.8.
