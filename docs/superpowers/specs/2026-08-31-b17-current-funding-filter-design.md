# B1.7 — Filtrer isCurrent côté lecture (le pendant "historisation" de la règle 6.4)

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-31
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 6.4 (aucune donnée n'est
écrasée, valeurs divergentes traçables)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(décision 8, historisation), `backend/src/Repository/FundingRepository.php`,
`backend/src/Service/AnalyticsService.php`

## Contexte — pourquoi ce n'est pas la tâche initialement prévue

Le roadmap B1.7 ("Gestion des conflits entre sources et historisation") demandait de valider que
le mécanisme d'historisation (`isCurrent`/`validFrom`/`validTo`) fonctionne avec de vraies données
multi-sources. Vérification en direct après la reconstruction de B1.5→B1.6 (voir
[`2026-08-31-funding-project-idempotency-design.md`](docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md)) :

- **Séparation entre sources, vérifiée avec de vraies données réelles** : Angola/Agriculture/2005
  a deux lignes courantes distinctes, une par source (BAD : 23 579 446 ; Banque Mondiale :
  50 700 000) - jamais fusionnées, exactement la règle 6.4.
- **Historisation réelle, vérifiée avec de vraies données** : Sénégal/Adaptation/2018 a une
  version historisée (55 000 000, `is_current=false`, `valid_to` renseigné) et une version
  courante (185 000 000) - deux vrais projets Banque Mondiale distincts agrégés dans le même run,
  correctement historisés, rien écrasé.

Le mécanisme d'**écriture** fonctionne donc déjà correctement. Mais en vérifiant le côté
**lecture** (l'API que le dashboard consomme), un vrai bug distinct a été trouvé :
`FundingRepository` ne filtre `isCurrent = true` **nulle part** - ni dans le listing/recherche/
export (`FundingController`), ni dans les agrégats (`findFinancingTrendsAggregate`,
`findSectorDistributionAggregate`), ni dans les stats du Hero (`countDistinctCountries`,
`count([])`, `countDistinctSources`). Aucun filtre Doctrine global ne le fait non plus (`isCurrent`
n'apparaît que dans les entités `Funding`/`Emission` elles-mêmes, jamais dans une requête).

**Impact mesuré en direct sur la base réelle** : somme de toutes les lignes `Funding` = 526
614 769 743 ; somme des lignes courantes uniquement (le vrai total) = 319 543 983 336 - soit un
gonflement d'environ 65% sur tous les chiffres actuellement affichés (graphiques "Tendances de
financement", "Répartition sectorielle", stats du Hero, tableau/export de financement).
`Emission` n'est pas concerné : `EmissionRepository` n'a aucune requête personnalisée, jamais
branché sur l'Analytics (B1.4, scope collecte uniquement).

**Décision de ce document, confirmée par Serge** : le vrai contenu de B1.7 est ce correctif de
lecture, pas juste une note de validation - l'historisation écrite n'a de valeur que si les
consommateurs la respectent.

## Décision 1 — Filtrer `isCurrent = true` à la source, une fois par requête

Chaque méthode de `FundingRepository` qui lit des lignes `Funding` doit exclure les versions
historisées :

- `criteriaQueryBuilder()` (partagée par `findByCriteria`, `countByCriteria`, `streamByCriteria` -
  listing, pagination, export CSV) : ajouter `->andWhere('funding.isCurrent = true')` une seule
  fois dans le générateur partagé, pas dans chacun des 3 appelants séparément - exactement la
  raison d'être de cette méthode (documentée dans son propre docblock : "so the two queries can
  never apply a different set of filters").
- `findFinancingTrendsAggregate()` et `findSectorDistributionAggregate()` : ajouter
  `->where('funding.isCurrent = true')`.
- `countDistinctCountries()` et `countDistinctSources()` : ajouter
  `->where('funding.isCurrent = true')`.
- `AnalyticsService::getHeroStats()` : `count([])` devient `count(['isCurrent' => true])`
  (méthode `count()` héritée de `ServiceEntityRepository`, qui construit son propre `WHERE` à
  partir du tableau de critères - pas besoin de toucher `FundingRepository` pour celui-ci).

Aucun paramètre d'API n'expose `isCurrent` (pas de cas d'usage aujourd'hui pour consulter
l'historique via l'API - aucune UI ne le demande) : le filtre est **toujours actif**, pas
optionnel. Si un futur besoin réel de consulter l'historique apparaît, ce sera une décision
séparée et explicite, pas un opt-out de celle-ci.

## Décision 2 — Pas de migration, pas de changement de contrat API

Aucun changement de schéma (la colonne `isCurrent` existe depuis A1.3). Aucun changement de forme
de réponse API - `isCurrent` reste un champ interne jamais exposé (déjà vérifié par
`testResponseShapeMatchesContractAndHidesInternalFields`) ; seul l'ensemble des lignes retournées
change (moins de lignes, montants corrects).

## Testing approach

- Nouveau test dans `FundingControllerTest.php` : ajoute une ligne `Funding` historisée
  (`isCurrent = false`) satisfaisant un filtre existant du jeu de données seedé, vérifie que le
  total retourné par `/api/funding` ne change pas.
- Nouveau test dans `AnalyticsControllerTest.php` : ajoute une ligne `Funding` historisée partageant
  la même clé de dédoublonnage qu'une ligne courante déjà seedée (même
  source/pays/secteur/année/type, comme un vrai cas réel de révision), vérifie que
  `financing-trends` et `hero-stats` (`fundingRecords`) ne changent pas.
- Suite PHPUnit complète relancée avant/après (comparaison avec la baseline documentée dans
  `docs/known-issues-backend-phpunit.md`) pour confirmer aucune régression au-delà des erreurs
  déjà connues et non liées.
- Vérification en direct après déploiement : comparer `sum(funding.amount) WHERE is_current=true`
  (SQL direct) avec la somme retournée par `GET /api/analytics/financing-trends` - doivent
  correspondre exactement.

## Documentation

`README.md` gagne un nouveau point d'attention documentant ce bug réel (root cause, ampleur
mesurée, correctif) et une sous-section dans la partie pertinente. `docs/roadmap-volet-b.html`
n'est pas mis à jour ici (document de suivi Volet B distinct, hors périmètre de cette correction
technique).
