# B3.3 — Bascule du frontend des données de démonstration vers les données réelles

Status: Approved
Author: Serge (with Claude)
Date: 2026-09-08
Spec reference: plan d'implémentation, tâche B3.3 (« Basculer progressivement le frontend des
données de démonstration vers les données réelles », livrable « Frontend alimenté par les données
réelles, jeu de démonstration désactivé ») ; cahier des charges section 2 (principe directeur : le
frontend du Volet A ne doit pas changer), section 5.7 (jeu de démonstration marqué)
Architecture reference:
[`docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md`](docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md)
(précédent direct - même patron de filtre systématique côté lecture),
`backend/src/Repository/FundingRepository.php`, `backend/src/Repository/EmissionRepository.php`,
`backend/src/Service/AnalyticsService.php`

## Contexte

Le pipeline Volet B a désormais collecté de vraies données dans cet environnement : **3005 lignes
`funding` réelles** (`validation_status = 'validated'` : World Bank 1521, AfDB 1117, GCF 319, OPEC
Fund PDF 48) et **840 lignes `emission` réelles** (PNUE/IEA). Elles coexistent avec le jeu de
démonstration A1.6 : **1080 lignes `funding` en `validation_status = 'demo'`**.

**Aucune requête de lecture ne filtre `validation_status` nulle part** - exactement le même angle
mort que B1.7 avait trouvé pour `is_current`. Résultat mesuré en direct : `GET /api/analytics/
hero-stats` renvoie `fundingRecords: 4085` (1080 démo + 3005 réel confondus), `activeSources: 6`
(sources démo + réelles mélangées). Chaque graphique, le tableau d'extraction, l'export CSV/XLSX
et les stats du Hero mélangent donc données inventées et données réelles.

L'enum `App\Entity\Enum\ValidationStatus` n'a que deux cas : `Demo = 'demo'` et
`Validated = 'validated'`. Les processors Volet B (`funding_validator.py` / `emission_validator.py`)
écrivent **toujours** `'validated'` (vérifié : `INSERT ... validation_status ... 'validated'`).
Les rejets de validation ne sont jamais insérés dans `funding`/`emission` - ils partent sur le
topic Kafka `.rejets`. La table `funding` ne contient donc, par construction, que `demo` ou
`validated`.

## Décision 1 — Un filtre `validation_status = 'validated'` systématique côté lecture

Même mécanisme que B1.7 : chaque requête de `FundingRepository` / `EmissionRepository` qui lit des
lignes destinées au frontend exclut les lignes `demo`. **Liste blanche** (`= 'validated'`), pas
liste noire (`<> 'demo'`) - défensif : un futur statut (`pending`, `rejected`...) ne fuiterait pas
par accident.

`FundingRepository` :
- `criteriaQueryBuilder()` (partagée par `findByCriteria` / `countByCriteria` /
  `streamByCriteria` - listing, pagination, export) : ajouter
  `->andWhere('funding.validationStatus = :validated')->setParameter('validated', ValidationStatus::Validated)`
  une seule fois, à côté du `funding.isCurrent = true` déjà présent.
- `findFinancingTrendsAggregate()`, `findSectorDistributionAggregate()`,
  `findCountryDistributionAggregate()` : ajouter le même `->andWhere(...)`.
- `countDistinctCountries()`, `countDistinctSources()` : idem.

`AnalyticsService::getHeroStats()` :
- `fundingRepository->count(['isCurrent' => true])` devient
  `count(['isCurrent' => true, 'validationStatus' => ValidationStatus::Validated])` (méthode
  `count()` héritée de `ServiceEntityRepository`, construit son `WHERE` à partir du tableau).

`EmissionRepository` (B3.2) :
- `distinctYearsWithCurrentData()` et `totalsByYear()` : ajouter
  `->andWhere('emission.validationStatus = :validated')`. Aucune ligne `emission` n'est `demo`
  aujourd'hui, mais le filtre garde le comportement cohérent avec `funding` et protège si une
  future fixture ajoute des émissions de démonstration.

Le filtre est **toujours actif**, jamais un paramètre d'API ni un flag d'environnement (décision
utilisateur explicite du 2026-09-08). Un besoin réel de consulter la démo via l'API (peu probable)
serait une décision séparée.

## Décision 2 — Hors périmètre : rapports, notifications, catalogue de sources

- **`report` / `notification`** : aucune tâche Volet B ne produit de vrais rapports ni de vraies
  notifications - il n'existe aucune donnée réelle à basculer. Les fixtures démo de ces deux
  tables restent en place (décision utilisateur explicite). `Report` porte d'ailleurs un
  `ReportStatus` (draft/published), pas un `ValidationStatus` - la notion de « démo » n'y est même
  pas modélisée. À traiter séparément le jour où une source réelle de rapports existe.
- **`source`** (page `sources.html`, `GET /api/sources` via la recherche) : le jeu de fixtures
  A1.6 crée exactement les sources que le pipeline réutilise (mêmes slugs : `world-bank-api`,
  `gcf-iati-datastore`, `afdb-iati-datastore`, etc. - voir `SourceFixtures`). Ces lignes `source`
  **sont** les vraies sources, pas des sources de démonstration. Le catalogue reste affiché
  intégralement. `activeSources` (Hero) devient toutefois plus juste : il ne comptera plus que les
  sources réellement référencées par au moins une ligne `funding` validée.
- **`country` / `sector`** : données de référence partagées, jamais « démo ».

## Décision 3 — Bascule unique, pas de mécanisme progressif par source

Les 5 connecteurs ont tourné, les données réelles sont là (~3000 `funding` + 840 `emission`). Le
filtre est activé en une fois (décision utilisateur explicite). B3.4 (comparaison des valeurs
affichées aux requêtes SQL de contrôle) valide juste après. Pas de toggle par source : la donnée
démo n'est de toute façon pas taguée par source réelle (les fixtures sont synthétiques,
déterministes), un « progressif par source » n'aurait pas de sens sur ce jeu.

## Décision 4 — « Jeu de démonstration désactivé »

Le livrable demande que le jeu de démo soit « désactivé ». Deux niveaux :
1. **Invisible côté frontend** : garanti par le filtre de la Décision 1 - même si les fixtures
   sont chargées, aucune ligne `demo` n'atteint l'API.
2. **Non chargé en production** : `doctrine:fixtures:load` n'est de toute façon jamais exécuté sur
   l'environnement Render (A3.8) - il n'y est lancé que manuellement sur un Codespace de dev. Le
   README gagne une note explicite : **ne pas** charger les fixtures sur un environnement destiné
   à servir des données réelles (elles resteraient invisibles grâce au filtre, mais autant ne pas
   les avoir). Les fixtures restent l'outil de dev/test normal.

Aucune suppression physique des lignes `demo` existantes (décision utilisateur : filtre, pas
DELETE) - elles restent en base, inertes, réversibles.

## Décision 5 — Aucun changement de contrat API, aucun changement frontend

Principe directeur du cahier des charges (section 2) : le frontend du Volet A ne doit pas bouger.
`validation_status` reste un champ interne de la réponse `GET /api/funding` (déjà exposé
aujourd'hui avec la valeur `demo`/`validated` par ligne - après B3.3 il vaudra toujours
`validated`, ce qui ne casse aucun consommateur). Aucun composant React, aucun hook, aucune route
ne change. Seul l'**ensemble** des lignes retournées rétrécit (et les agrégats se recalculent en
conséquence).

## Testing approach

Le jeu seedé par les helpers `seedDataset()` de la suite de tests utilise aujourd'hui
`ValidationStatus::Demo` (hérité des fixtures). Après B3.3, « ce que le frontend voit » = données
`validated` - les helpers doivent donc seeder `ValidationStatus::Validated` pour que les
assertions existantes restent valides (elles testent le comportement réel, pas une simulation -
philosophie déjà établie du projet).

- `FundingControllerTest`, `AnalyticsControllerTest`, `FundingExportTest`,
  `PublishKpiSnapshotCommandTest`, `EmissionRepositoryTest` : leurs helpers de seed passent de
  `Demo` à `Validated`. `FundingTest` (test unitaire d'entité) et `SchemaLayer2Test` (persistance)
  ne sont pas concernés - ils ne font aucune requête filtrée.
- Nouveau test dans `FundingControllerTest` : seed une ligne `Funding` `Demo` satisfaisant un
  filtre existant, vérifie que `GET /api/funding` ne la retourne pas et que `meta.total` ne bouge
  pas.
- Nouveau test dans `AnalyticsControllerTest` : seed une ligne `Funding` `Demo` de gros montant,
  vérifie que `financing-trends`, `sector-distribution`, `country-distribution` et
  `hero-stats.fundingRecords` ne la comptent pas.
- Nouveau test dans `EmissionRepositoryTest` : seed une ligne `Emission` `Demo`, vérifie qu'elle
  est exclue de `distinctYearsWithCurrentData()` et `totalsByYear()`.
- Suite complète relancée avant/après (baseline connue) - aucune régression au-delà des écarts
  déjà documentés.
- Vérification en direct sur le Codespace : `GET /api/analytics/hero-stats` doit passer de
  `fundingRecords: 4085` à `3005` ; `SELECT count(*) FROM funding WHERE is_current AND
  validation_status = 'validated'` doit correspondre exactement à la somme retournée par
  `financing-trends`.

## Documentation

`README.md` : nouvelle sous-section « B3.3 — Bascule démo → réel » dans la partie Volet B,
nouveau point d'attention (le filtre `validation_status`, jumeau de celui de B1.7), mise à jour du
tableau « État d'avancement » (B3.3 ✅), et note dans la checklist de reprise (« ne pas charger les
fixtures sur un environnement qui sert du réel »).
