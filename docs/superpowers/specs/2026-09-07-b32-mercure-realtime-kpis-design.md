# B3.2 — Diffusion des KPIs réels via Mercure

Status: Approved
Author: Serge (with Claude)
Date: 2026-09-07
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 5.2.e (agrégats analytiques,
notamment la réduction CO2) ; plan d'implémentation, tâche B3.2 ("Connecter la diffusion des KPIs
réels et GreenAccess au hub Mercure")
Architecture reference: `backend/src/Command/PublishKpiSnapshotCommand.php` (A3.1),
`frontend/src/react/hooks/useMercureKpis.js` (A3.2), `backend/src/Service/AnalyticsService.php`,
`docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md` (précédent direct pour le
filtre `isCurrent`)

## Contexte

Le plan officiel scope B3.2 comme "Connecter la diffusion des KPIs réels **et GreenAccess** au hub
Mercure", avec pour dépendances déclarées B3.1 et A3.1. Deux constats avant de coder :

1. **GreenAccess est hors de portée.** Toute la Phase B2 (B2.1 à B2.8) est au statut "Bloqué" dans
   le plan - aucune donnée GreenAccess n'existe nulle part dans le système (ni Kafka, ni
   TimescaleDB). Il n'y a littéralement rien à diffuser. Même décision que B3.1, qui a déjà avancé
   sans attendre B2 sur instruction explicite de l'utilisateur : cette tâche couvre uniquement les
   KPIs réels du Volet B déjà collectés (World Bank/GCF/AfDB/PNUE/OPEC Fund), pas GreenAccess.

2. **Le flux Mercure du financement est probablement déjà réel, sans changement de code.**
   `PublishKpiSnapshotCommand` (A3.1) republie déjà `AnalyticsService::getFinancingTrends()` et
   `getHeroStats()` - les mêmes méthodes que B3.1 a corrigées (filtre `isCurrent`, `year` au lieu
   de `collectionDate`). Le payload Mercure (`fundingTotalUsd`, `countriesCovered`) reflète donc
   déjà les vraies données à chaque republication (toutes les 60s). **Aucun code à changer ici** -
   seule une vérification en direct sur le Codespace (curl `/.well-known/mercure`, comparer au
   total réel) reste à faire, une fois le Codespace de nouveau disponible (bloqué par un problème
   de facturation GitHub au moment de la rédaction de ce document).

Le vrai travail restant est donc : **`AnalyticsService::getCo2Reduction()` retourne encore en dur
`{available: false, reason: "Aucune donnée d'émissions..."}`**, alors que B1.4 a livré une vraie
table `Emission` (840 lignes réelles, 35 des 54 pays NEV, source IEA/PNUE, indicateur SDG 9.4.1).
Le README documente déjà cet écart explicitement ("`getCo2Reduction()` reste volontairement non
rebranché... dans le cadre de B1.4"). B3.2 ("diffuser les KPIs réels... via Mercure") est
l'occasion naturelle de le combler : sans ce rebranchement, il manquerait un des 3 agrégats
analytiques prévus par la règle 5.2.e (tendances de financement, répartition sectorielle,
réduction CO2) au flux temps réel.

## Décision 1 — Définition du chiffre "réduction CO2"

`Emission.valueMt` est un **niveau** d'émission par pays/année (megatonnes), jamais une baisse
attribuée à un financement précis - rien dans le schéma ne relie une variation d'émissions à une
cause. Choix retenu (validé explicitement) : **variation totale entre les deux dernières années
disponibles, sur l'intersection des pays ayant une donnée les deux années**.

- Les deux années comparées sont les deux plus récentes pour lesquelles `Emission` a au moins une
  ligne `isCurrent = true` (peu importe si elles sont consécutives - PNUE a des trous de
  couverture par pays, jamais comblés par une hypothèse).
- Seuls les pays présents dans **les deux** années entrent dans la somme - jamais un pays manquant
  une année traité comme zéro ("Absence ≠ zéro", déjà le principe directeur du Volet B, section
  6.4 du cahier des charges).
- `reductionMt = totalAnnéePrécédente - totalAnnéeLaPlusRécente`. **Signe honnête** : positif = les
  émissions ont baissé (une vraie "réduction"), négatif = elles ont augmenté - affiché tel quel,
  jamais tronqué à zéro ni maquillé.
- Si moins de 2 années disponibles, ou aucun pays commun entre les deux années les plus récentes :
  `available: false` avec un `reason` explicite (même pattern que le cas actuel), jamais une
  fabrication de chiffre.

Alternative écartée : renommer la tuile en "Émissions CO2 totales" (dernière année seule, sans
comparaison). Écartée par décision explicite - la variation d'une année sur l'autre est le chiffre
demandé par le plan ("réduction CO2"), et rester honnête sur le signe (y compris négatif) est
préférable à changer le sens de la métrique.

## Décision 2 — `EmissionRepository` + `AnalyticsService::getCo2Reduction()`

Même filtre `isCurrent = true` que B1.7 a imposé pour `Funding` (précédent direct, même
raisonnement : une ligne historisée ne doit jamais entrer dans un agrégat de lecture).

Nouvelle méthode `EmissionRepository::totalsByYear(int $year): array<int, string>` (clé = id pays,
valeur = somme `valueMt` en string décimal, `GROUP BY country`, `WHERE year = :year AND isCurrent =
true`) et `EmissionRepository::latestTwoYears(): array` (les 2 valeurs d'`year` les plus récentes
ayant au moins une ligne `isCurrent = true`, `SELECT DISTINCT year ... ORDER BY year DESC LIMIT
2`). `AnalyticsService::getCo2Reduction()` orchestre : récupère les 2 années, appelle
`totalsByYear()` sur chacune, intersecte les clés (pays communs), somme chaque côté sur cette
intersection, calcule le delta. Toute la logique d'intersection reste dans le service (testable en
isolation, pas de dépendance à la structure de la requête SQL) - le repository ne fait que
retourner des totaux bruts par pays/année, comme `FundingRepository` le fait déjà pour ses propres
agrégats.

Cache Redis 15 minutes conservé à l'identique (`CACHE_KEY_CO2_REDUCTION`, même TTL que les 2 autres
agrégats analytics) - aucun changement d'infrastructure de cache.

## Décision 3 — Nouvelle forme de réponse (jamais un changement cassant en pratique)

Forme actuelle documentée en Swagger : `{available: bool, data: null, reason: string|null}` - mais
`data` n'a **jamais** été autre chose que `null` depuis la création de cet endpoint (A2.5). Il n'y
a donc aucun consommateur réel d'un `data` non-null à préserver. Nouvelle forme quand
`available: true` :

```json
{
  "available": true,
  "data": {
    "reductionMt": 12.7,
    "latestYear": 2025,
    "previousYear": 2024,
    "countriesCompared": 28
  },
  "reason": null
}
```

`reductionMt` peut être négatif (voir Décision 1). `backend/src/Controller/AnalyticsController.php`
(attributs `#[OA\...]`) et le fichier OpenAPI généré sont mis à jour en même temps que le code, pas
après coup - même rigueur que A3.9.

## Décision 4 — Frontend : tuile CO2 réelle + badge "Direct" Mercure

`VisualizationsPage.jsx`'s `useCo2Reduction()` (actuellement `setValue(String(body.data))`, qui
produirait `"[object Object]"` avec la nouvelle forme) est réécrit pour lire
`body.data.reductionMt` et construire un contexte lisible à partir de `latestYear`/`previousYear`/
`countriesCompared` (ex. "2025 vs 2024 · 28 pays comparés", plus une mention explicite si le signe
est négatif - une hausse ne doit jamais être présentée silencieusement comme une réduction).

`PublishKpiSnapshotCommand::publishSnapshot()` ajoute un champ `co2ReductionMt` (nullable - absent
ou `null` si `getCo2Reduction()` retourne `available: false`, jamais `0` par défaut) au payload
Mercure existant, même topic, même cadence (60s). `useMercureKpis.js` ne change pas (il expose déjà
tout le `snapshot` brut) ; `VisualizationsPage.jsx` lit `snapshot?.co2ReductionMt` exactement comme
il lit déjà `snapshot?.fundingTotalUsd` pour le badge "Direct" de la tuile financement, et applique
le même badge à la tuile CO2 quand cette valeur est présente.

## Portée exclue

- **GreenAccess** : voir Contexte - aucune donnée n'existe, rien à diffuser. À reprendre quand B2
  sortira du statut "Bloqué".
- **Historique des connecteurs** (World Bank/GCF/AfDB/OPEC Fund) : déjà diffusés en temps réel par
  le mécanisme existant (Décision Contexte, point 2) - aucun changement de code, seulement une
  vérification en direct.
- **Émission en base < 2 années** : couvert par le cas `available: false` (Décision 1), pas un bug
  à corriger séparément.

## Testing approach

- `EmissionRepositoryTest` (nouveau) : jeu de données avec 3 années, un pays absent une année, une
  ligne historisée (`isCurrent = false`) sur une clé qui existe aussi en courant - vérifie que
  `latestTwoYears()` ignore les années sans ligne courante et que `totalsByYear()` exclut la ligne
  historisée.
- `AnalyticsServiceTest` (ou nouveau test ciblé) : vérifie le calcul du delta sur l'intersection
  (pays absent une année exclu des deux côtés, pas traité comme zéro), le signe négatif en cas de
  hausse réelle, et le cas `available: false` avec moins de 2 années.
- `AnalyticsControllerTest::testCo2ReductionEndpoint` : adapté à la nouvelle forme de réponse.
- `PublishKpiSnapshotCommandTest` (A3.5) : adapté pour vérifier la présence de `co2ReductionMt`
  dans le payload publié (mock/stub `getCo2Reduction()` pour les deux cas available true/false).
- Suite complète PHPUnit relancée avant/après sur le Codespace (comparaison avec la baseline
  documentée), aucun frontend test n'existe dans ce dépôt (constat déjà fait pour A3.11).
- Vérification en direct une fois le Codespace disponible : comparer le résultat de
  `GET /api/analytics/co2-reduction` à une requête SQL de contrôle directe sur `emission`, et
  confirmer via `curl /.well-known/mercure` (topic KPI) que `co2ReductionMt` apparaît bien dans le
  payload publié - même exigence de preuve que tout le reste de ce projet ("vérifié en direct",
  jamais seulement supposé).

## Documentation

`README.md` gagne une nouvelle section "B3.2 — Diffusion des KPIs réels via Mercure" (état
d'avancement Phase B3) documentant : le rebranchement CO2 réel, la vérification du flux financement
déjà réel, et l'exclusion explicite de GreenAccess (même format que les sections B1.x/B3.1
existantes).
