# Plan d'implémentation C1 -> C3 (sprint 2 semaines)

**Source de périmètre :** `NEV_Climate_Finance_Roadmap_C1_C3.xlsx` (24 fonctionnalités, 3 paliers).
**Cadre :** 2 semaines calendaires = **10 jours ouvrés**.
**Date de rédaction :** 2026-09-10. **Base de code :** `developp` @ `b1e6ed2`.

---

## 1. Lecture réaliste du périmètre

Les 3 paliers de la roadmap répondent à 3 questions :

| Palier | Question | Nature |
|---|---|---|
| **C1** (Core / MVP) | *Où est l'argent ?* | Visualisation / exploration de données déjà collectées |
| **C2** (Intelligence / V2) | *Où l'argent devrait-il aller ?* | Scoring, mise en relation, assistant IA |
| **C3** (Advanced / V3) | *L'argent atteint-il la bonne cible ?* | Détection d'écarts, équité, transparence, alertes |

**Ce qui rend 2 semaines possible :**

1. **~40 % de C1 existe déjà** dans le Volet A (dashboard, carte Afrique, recherche, tendances, fiches sources, agrégats analytiques mis en cache).
2. **Les données réelles existent déjà** : ~3 005 lignes `funding` (World Bank, GCF, BAD, OPEC Fund), les émissions CO2 (PNUE), le pipeline Kafka/Airflow/MinIO.
3. **Les scores C2/C3 sont livrés en v1 « indicateurs pondérés transparents »**, pas en modèles ML entraînés. C'est un choix assumé et défendable : en finance climatique, l'explicabilité d'un score prime sur sa sophistication. Le passage à `scikit-learn`/`XGBoost` (prévu par la roadmap) est une itération **post-sprint**.
4. **L'AI Copilot (C2.1) est livré en RAG sur un corpus borné** (les specs du projet + les rapports + un export des données), pas un agent généraliste.

**Ce qui NE tiendra PAS en 2 semaines** (explicitement reporté, voir §7) : modèles ML entraînés, PostGIS sous-national complet pour C3.2, connecteurs temps réel pour C1.8, durcissement production de tous les scores.

**Hypothèse d'équipe :** 2 développeurs à plein temps (~20 jours-homme). En solo, compter 4 semaines ou réduire le périmètre aux niveaux P0/P1 uniquement (voir §3).

---

## 2. Principes transverses

- **Une branche par palier** : `feature/c1`, `feature/c2`, `feature/c3`, mergées sur `developp` en fin de palier après recette.
- **Convention projet respectée** : chaque C-tâche non triviale reçoit une **note de conception courte** (½ page : décision + alternatives écartées) avant le code, committée sous `docs/superpowers/specs/`. Les tweaks de config n'en ont pas besoin.
- **TDD** : test qui échoue -> implémentation -> test qui passe -> commit. Vaut pour les entités, les endpoints, les services de scoring.
- **Pas de nouvelle source de vérité** : tout agrégat réutilise `AnalyticsService` / `FundingRepository` (mêmes chiffres que l'API REST, même cache Redis 15 min).
- **`-d memory_limit=512M`** sur `bin/phpunit` (sinon CI cassée, point d'attention #51).
- **Réutilisation du design system** : composants `Nev*` (`frontend/src/react/components/ui/`), i18n FR/EN obligatoire sur tout texte visible, thème vert `--nev-*`.
- **Filtres systématiques** : `is_current = true` ET `validation_status = 'validated'` sur toute lecture (jumeaux B1.7 / B3.3).

---

## 3. Niveaux de finition

| Niveau | Signification | Critère de recette |
|---|---|---|
| **P0** | Production-ready | Données réelles, tests, i18n, cache, doc Swagger, gère les cas vides |
| **P1** | Fonctionnel, à durcir | Données réelles, tests du chemin nominal, i18n ; perf/edge-cases en dette assumée |
| **P2** | Règles transparentes / pilotée | Logique explicite documentée (pas de ML), données réelles quand dispo, sinon jeu de démonstration étiqueté |

---

## 4. Fondations techniques — J0 (½ journée, à faire avant tout le reste)

| # | Tâche | Détail | Livrable |
|---|---|---|---|
| F1 | Enrichir `funding` d'un instrument financier | Nouvel enum `FundingInstrument` (`grant`, `loan_concessional`, `loan_non_concessional`, `guarantee`, `equity`, `results_based`, `unknown`) + colonne nullable `funding.instrument`. Les connecteurs IATI (GCF/BAD) portent déjà `default-finance-type` -> mapping dans `funding_validator.py`. Migration + backfill `unknown`. | Migration, enum, mapping pipeline |
| F2 | Table générique de scores | `score` (`subject_type` : country/source/project/funding, `subject_id`, `score_type`, `value` 0-100, `grade` A-E, `breakdown` JSONB des indicateurs, `computed_at`). Une seule table pour C2.4/C2.6/C2.7/C2.8/C3.3/C3.6. | Entité `Score`, migration, `ScoreRepository` |
| F3 | `ScoreService` + registre d'indicateurs | Service PHP : un score = somme pondérée d'indicateurs normalisés 0-1, chaque indicateur est une classe implémentant `ScoreIndicatorInterface` (`code()`, `weight()`, `compute(subject): float`, `explain(): string`). Le `breakdown` JSONB rend le score auditable. | `ScoreService`, interface, commande `app:scores:recompute` |
| F4 | Modèle `project` / `opportunity` | `project` (titre, pays, secteur, instrument recherché, montant recherché, stade `pipeline_stage`, description, porteur) + `opportunity` (bailleur, fenêtre de dépôt, critères, montant enveloppe). Sert C1.8, C2.2, C2.3, C2.4, C2.5. Seed de démonstration réaliste (10-15 projets, 8-10 opportunités) étiqueté `demo`. | Entités, migrations, fixtures |
| F5 | Service IA `ai/` (FastAPI) | Nouveau service Docker `ai` (FastAPI + `pgvector` sur la base existante, extension `vector`). Endpoints : `POST /ai/ask` (RAG), `POST /ai/classify` (taxonomie C2.7), `POST /ai/greenwashing` (C3.5). Réutilise `GEMINI_API_KEY`. `docker-compose.yml` : +1 service, healthcheck, pas de port public (proxifié par le backend). | Squelette `ai/`, Dockerfile, entrée compose |
| F6 | Ingestion corpus RAG | Script `ai/ingest.py` : découpe + embeddings de `docs/`, `README.md`, table `report`, un export CSV des agrégats `funding` -> table `rag_chunk(content, embedding vector, source_ref)`. Rejouable (idempotent par hash). DAG Airflow `indexer_rag` trimestriel. | Script, table, DAG |
| F7 | Données de référence CDN (NDC) | Table `ndc_target` (pays, secteur, cible de réduction %, année cible, montant besoin déclaré, conditionnel/inconditionnel). Seed manuel à partir des CDN publiques des ~15 principaux pays NEV (le reste en `null` explicite). Sert C2.6, C3.1, C3.3. | Entité, migration, seed |

> F1-F4 et F7 sont du travail backend PHP/SQL classique. F5-F6 sont le seul vrai bloc nouveau (service Python). Répartir : **Dev A** = F1-F4+F7, **Dev B** = F5-F6.

---

## 5. Planning jour par jour

| Jour | Dev A (backend / données) | Dev B (frontend / IA) |
|---|---|---|
| **J0** (½ j) | F1, F2, F3, F4, F7 | F5, F6 |
| **J1** | C1.5 (Sankey : endpoint flux bailleur->pays->secteur) + C1.6 (durcir Trends existant) | C1.1 (Dashboard : consolider index/visualizations en une vue) |
| **J2** | C1.4 (Institution Profiles : endpoint fiche source + portefeuille) + C1.7 (Instruments : agrégats par `instrument`) | C1.2 (Carte : couches secteur/instrument/année sur `CountryMap`) + C1.3 (Recherche à facettes) |
| **J3** | C1.8 (Opportunity Radar : endpoint `opportunity` + filtres) | C1.4 + C1.5 + C1.7 front (fiches, Sankey, explorer) |
| **J4** | Recette C1 + corrections | C1.8 front + C1.1 finitions + recette C1 |
| **J5** | Merge `feature/c1` -> `developp`, tag `C1-done`. Marge / rattrapage. | idem |
| **J6** | C2.4 (Bankability), C2.7 (Taxonomy via `ai/classify`), C2.8 (Investment Risk) : indicateurs + `ScoreService` | C2.1 (AI Copilot : proxy backend `/api/ai/ask` + composant chat) |
| **J7** | C2.6 (NDC Alignment score), C2.2 (Matching Engine : règles projet<->opportunité + projet<->source) | C2.3 (Project Pipeline : Kanban drag-drop sur `pipeline_stage`) |
| **J8** | C2.5 (Impact Calculator : formules tCO2/€ par secteur) + recette C2 | C2.x front (scores, matching, copilot) + recette C2 |
| **J9** | C3.1 (Gap Detector : besoin CDN - reçu), C3.3 (Equity Score), C3.6 (Transparency Score) : indicateurs | C3.4 (Blended Finance Simulator : calcul de levier interactif) + C3.5 front (Greenwashing via `ai/`) |
| **J10** | C3.2 (Local Finance Tracker : part « niveau local » par proxy de mots-clés/instrument) + C3.7 (Recommendation Engine : classement des couples pays x secteur sous-financés) + C3.8 (Smart Alerts : moteur de règles sur `notification`) | C3.x front + recette C3 + merge `feature/c3` -> `developp` |

> Marge réelle : **J5 et les fins de journée**. Si un item déborde, il passe en P2 ou dans la liste « post-sprint » (§7) — on ne décale pas le merge de palier.

---

## 6. Détail par fonctionnalité

Format : **Objectif** · *Existant réutilisé* · **À faire** · `API` · Front · Données · **Finition** · Estim.

### Palier C1 — « Où est l'argent ? »

#### C1.1 Dashboard
**Objectif :** page d'accueil décisionnelle : total des flux, top pays/secteurs/bailleurs, tendance, dernières mises à jour du pipeline.
*Existant :* `IndexPage.jsx`, `VisualizationsPage.jsx`, `/api/analytics/hero-stats`, `/api/analytics/financing-trends`, `useMercureKpis`.
**À faire :** une vue unique « Dashboard » (nouvelle route `/dashboard` + entrée nav) réagençant les tuiles existantes + une tuile « fraîcheur des données » (date de dernière collecte par source). Pas de nouvel endpoint majeur ; ajout `/api/analytics/data-freshness`.
`API` : `GET /api/analytics/data-freshness`.
Front : `DashboardPage.jsx`.
**Finition : P0.** Estim. : 1 j (Dev B).

#### C1.2 Africa Map
**Objectif :** carte choroplèthe interactive, filtrable par secteur / instrument / année / type de financement, clic pays -> fiche.
*Existant :* `CountryMap.jsx` (jsvectormap), `/api/analytics/country-distribution`.
**À faire :** paramétrer `country-distribution` avec `?sector=&instrument=&year=&fundingType=` ; ajouter un sélecteur de couche ; lien clic -> `/institutions?country=` ou fiche pays. (Mapbox de la roadmap : rester sur jsvectormap, déjà intégré et sans coût — noter l'écart dans la note de conception.)
`API` : extension de `GET /api/analytics/country-distribution` (params).
Front : contrôles de couche sur `CountryMap`.
**Finition : P0.** Estim. : 0,5 j.

#### C1.3 Search
**Objectif :** recherche à facettes sur financements + institutions + rapports + (nouveau) projets/opportunités.
*Existant :* `SearchController`, `unaccent`, `GlobalSearch.jsx`.
**À faire :** ajouter les facettes (pays, secteur, instrument, année, type, montant min/max) et les entités `project`/`opportunity` au périmètre de recherche ; page de résultats dédiée.
`API` : `GET /api/search?q=&type=&country=&sector=&instrument=&yearFrom=&yearTo=&amountMin=&amountMax=`.
Front : `SearchResultsPage.jsx`.
**Finition : P1.** Estim. : 0,5 j.

#### C1.4 Institution Profiles
**Objectif :** fiche par bailleur : portefeuille total, répartition pays/secteur/instrument, évolution, fiabilité de la source, transparence (lien C3.6).
*Existant :* `SourcesPage.jsx`, entité `Source` (`reliability`, `type`).
**À faire :** endpoint fiche + agrégats filtrés sur `source_id` ; page profil.
`API` : `GET /api/institutions/{sourceId}` (profil + agrégats).
Front : `InstitutionProfilePage.jsx`.
**Finition : P0.** Estim. : 1 j (A back + B front).

#### C1.5 Funding Flow Analytics (Sankey)
**Objectif :** diagramme de flux bailleur -> pays -> secteur (et filtrable par instrument).
*Existant :* `FundingRepository` agrégats.
**À faire :** endpoint renvoyant les nœuds + liens (`source -> country`, `country -> sector`), montants agrégés, top N + « autres ». Rendu Sankey côté front (D3-sankey chargé depuis cdnjs, ou `chartjs-chart-sankey`).
`API` : `GET /api/analytics/funding-flows?instrument=&year=&topN=`.
Front : composant `SankeyFlow.jsx` sur la page Visualisations.
**Finition : P1.** Estim. : 1 j back + 0,5 j front.

#### C1.6 Trends
**Objectif :** séries temporelles (par an) déclinables par pays / secteur / instrument / type ; comparaison de 2 dimensions.
*Existant :* `/api/analytics/financing-trends` (déjà par année + type).
**À faire :** généraliser la ventilation (`?groupBy=sector|instrument|country|type`), ajouter la moyenne mobile / le cumul.
`API` : extension `GET /api/analytics/financing-trends?groupBy=`.
Front : sélecteur de ventilation sur le graphe existant.
**Finition : P0.** Estim. : 0,5 j.

#### C1.7 Instruments Explorer
**Objectif :** explorer les flux par instrument financier (subvention vs prêt concessionnel vs garantie…), part de concessionnalité.
*Existant :* rien (dépend de **F1**).
**À faire :** agrégats `GROUP BY instrument` (montant, nb, part) + croisement instrument x secteur / instrument x pays ; indicateur « part concessionnelle ».
`API` : `GET /api/analytics/instrument-distribution?crossBy=sector|country`.
Front : `InstrumentsPage.jsx` (barres empilées + tableau).
**Finition : P1.** Estim. : 0,5 j back + 0,5 j front.

#### C1.8 Opportunity Radar
**Objectif :** liste + carte des opportunités de financement ouvertes (fenêtre de dépôt, secteur éligible, pays éligibles, enveloppe).
*Existant :* rien (dépend de **F4**).
**À faire :** CRUD lecture `opportunity` + filtres (secteur, pays, échéance, instrument) + badge « ferme bientôt » ; pas de connecteur automatique dans ce sprint (seed manuel + saisie admin).
`API` : `GET /api/opportunities`, `GET /api/opportunities/{id}`.
Front : `OpportunityRadarPage.jsx` (liste + filtres + calendrier des échéances).
**Finition : P2** (données saisies, pas collectées). Estim. : 0,5 j back + 0,5 j front.

### Palier C2 — « Où l'argent devrait-il aller ? »

#### C2.1 AI Copilot
**Objectif :** assistant conversationnel qui répond sur les données de la plateforme et le corpus documentaire, avec citations.
*Existant :* rien (dépend de **F5**, **F6**).
**À faire :** service `ai/` `POST /ai/ask` = recherche des k chunks les plus proches (`pgvector`) -> prompt Gemini avec contexte + garde-fou « réponds uniquement à partir du contexte, cite les `source_ref` » -> réponse + sources. Proxy backend `/api/ai/ask` (rate-limité par rôle, pas d'appel direct navigateur -> Gemini). Historique de conversation côté client (localStorage).
`API` : `POST /api/ai/ask` (proxy).
Front : `AiCopilotPanel.jsx` (tiroir latéral disponible sur toutes les pages).
**Finition : P1.** Estim. : 1 j (Dev B) + le squelette F5/F6.

#### C2.2 Matching Engine
**Objectif :** proposer, pour un projet, les bailleurs/opportunités les plus pertinents (et inversement).
*Existant :* dépend de **F4**.
**À faire :** score de correspondance par règles pondérées : concordance secteur (0,35), pays éligible (0,25), instrument recherché vs proposé (0,2), ordre de grandeur du montant (0,1), fenêtre temporelle ouverte (0,1). Renvoie un top N trié avec le détail des composantes.
`API` : `GET /api/projects/{id}/matches`, `GET /api/opportunities/{id}/candidates`.
Front : encart « Financements pertinents » sur la fiche projet.
**Finition : P2** (règles). Estim. : 1 j back + 0,3 j front.

#### C2.3 Project Pipeline (Kanban)
**Objectif :** suivre les projets par stade : `idea -> scoping -> structuring -> fundraising -> financed -> rejected`.
*Existant :* dépend de **F4**.
**À faire :** endpoints de déplacement de stade + historique ; tableau Kanban glisser-déposer.
`API` : `GET /api/projects?stage=`, `PATCH /api/projects/{id}` (`{stage}`).
Front : `ProjectPipelinePage.jsx` (colonnes, drag-drop, filtre pays/secteur).
**Finition : P1.** Estim. : 1 j (Dev B) + 0,3 j back.

#### C2.4 Bankability Score
**Objectif :** score 0-100 (grade A-E) de « prêt à être financé » d'un projet.
*Existant :* **F2**, **F3**, **F4**.
**À faire :** indicateurs : complétude du dossier (0,25), présence d'un porteur identifié (0,1), cohérence montant/secteur vs comparables réels (0,2), instrument réaliste pour le pays (0,15), risque pays (réutilise C2.8) inversé (0,3). `breakdown` JSONB affiché.
`API` : `GET /api/projects/{id}/scores/bankability`.
Front : jauge + tableau des composantes sur la fiche projet.
**Finition : P2.** Estim. : 0,5 j.

#### C2.5 Impact Calculator
**Objectif :** estimer l'impact d'un montant (tCO2 évitées/an, MW installés, personnes bénéficiaires) selon le secteur.
*Existant :* émissions PNUE pour calibrer les ratios sectoriels.
**À faire :** table `impact_factor` (secteur -> €/tCO2, €/MW, €/bénéficiaire, avec source méthodo) seedée à partir de facteurs publics (IRENA, GCF results). Calcul + fourchette d'incertitude.
`API` : `POST /api/impact/estimate` (`{sector, amountUsd, country?}`).
Front : formulaire + résultat avec fourchette, sur une page dédiée et en encart projet.
**Finition : P2** (facteurs statiques documentés). Estim. : 0,5 j back + 0,3 j front.

#### C2.6 NDC Alignment
**Objectif :** mesurer si les flux d'un pays sont alignés avec ses priorités CDN (secteurs prioritaires, cible de réduction, besoin déclaré).
*Existant :* dépend de **F7**.
**À faire :** score par pays : couverture des secteurs prioritaires CDN par des financements réels (0,5), ratio reçu/besoin déclaré (0,3), présence de financement inconditionnel vs conditionnel (0,2).
`API` : `GET /api/countries/{iso}/scores/ndc-alignment`.
Front : encart sur la fiche pays / carte.
**Finition : P2.** Estim. : 0,5 j.

#### C2.7 Taxonomy Classification
**Objectif :** classer un financement / projet selon une taxonomie verte simplifiée (atténuation / adaptation / double / non pertinent ; + catégorie d'activité).
*Existant :* dépend de **F5**.
**À faire :** `POST /ai/classify` = Gemini en few-shot sur une taxonomie réduite (inspirée UE + Common Principles MDB) à partir du titre + description + secteur. Cache par hash. Traitement par lot des lignes `funding` réelles via un DAG.
`API` : `POST /api/classify` (proxy), champ `taxonomy_class` ajouté à `funding`/`project`.
Front : badge de classe + filtre.
**Finition : P2.** Estim. : 0,5 j back + 0,3 j front.

#### C2.8 Investment Risk Score
**Objectif :** score de risque d'un investissement = risque pays x risque secteur x risque projet.
*Existant :* **F2**, **F3**.
**À faire :** risque pays à partir d'indicateurs ouverts (volatilité des flux reçus, concentration des bailleurs, écart CDN) ; risque secteur (maturité, dépendance à un instrument) ; agrégation. Pas d'indice souverain tiers dans ce sprint.
`API` : `GET /api/countries/{iso}/scores/investment-risk`, `GET /api/projects/{id}/scores/investment-risk`.
Front : jauge sur fiche pays et fiche projet.
**Finition : P2.** Estim. : 0,5 j.

### Palier C3 — « L'argent atteint-il la bonne cible ? »

#### C3.1 Gap Detector
**Objectif :** identifier les couples pays x secteur où le besoin (CDN) dépasse largement le reçu.
*Existant :* **F7**, agrégats `funding`.
**À faire :** `gap = besoin_declare - somme_recue` par pays x secteur, classement, seuils d'alerte, visualisation carte + tableau.
`API` : `GET /api/analytics/gaps?by=country|sector|country-sector`.
Front : carte des écarts + tableau trié.
**Finition : P1.** Estim. : 0,5 j.

#### C3.2 Local Finance Tracker
**Objectif :** estimer la part des financements qui atteint réellement le niveau infra-national / local.
*Existant :* pas de donnée géographique fine (pas de PostGIS dans ce sprint).
**À faire :** proxy par mots-clés du descriptif (« community », « municipal », « local government », « decentralized »…) + instrument (`grant` + petits montants) -> flag `reaches_local` (booléen + confiance). Agrégat de la part locale par pays.
`API` : `GET /api/analytics/local-finance?country=`.
Front : indicateur « part locale estimée » sur la fiche pays, avec avertissement méthodo.
**Finition : P2** (proxy, pas géoréférencé). Estim. : 0,5 j. *PostGIS + géocodage projet = post-sprint.*

#### C3.3 Equity Score
**Objectif :** score d'équité de la répartition : les pays les plus vulnérables / les moins émetteurs reçoivent-ils une part juste ?
*Existant :* émissions PNUE, **F7**.
**À faire :** indicateurs : financement par habitant vs vulnérabilité (0,4), part reçue vs part du besoin CDN (0,3), financement par tonne de CO2 (inversé, 0,3). Score par pays + un indice global (type Gini sur la répartition).
`API` : `GET /api/analytics/equity`, `GET /api/countries/{iso}/scores/equity`.
Front : classement + courbe de concentration.
**Finition : P2.** Estim. : 0,5 j.

#### C3.4 Blended Finance Simulator
**Objectif :** simuler un montage mixte : entrée d'un montant public concessionnel -> levier privé attendu, coût du capital mélangé, part de risque couverte.
*Existant :* rien.
**À faire :** modèle paramétrique simple (ratios de levier par secteur/instrument issus de la littérature OCDE), curseurs interactifs, sortie graphique.
`API` : `POST /api/blended/simulate` (`{sector, publicAmount, instrumentMix, country?}`).
Front : `BlendedFinancePage.jsx` (curseurs + graphe waterfall).
**Finition : P2.** Estim. : 0,5 j back + 0,5 j front.

#### C3.5 Greenwashing Detector
**Objectif :** signaler un risque d'écoblanchiment dans la déclaration d'un financement (intitulé « vert » mais activité douteuse, sur-classification adaptation, incohérence secteur/montant).
*Existant :* **F5**, C2.7.
**À faire :** `POST /ai/greenwashing` = Gemini sur des règles de red-flags + heuristiques (classe taxonomie faible mais libellé fort, instrument non concessionnel présenté comme aide, ratio impact/€ aberrant). Score de risque + justification.
`API` : `POST /api/greenwashing/check` (proxy), badge sur les lignes concernées.
Front : pastille « à vérifier » + panneau explicatif.
**Finition : P2.** Estim. : 0,5 j back + 0,3 j front.

#### C3.6 Transparency Score
**Objectif :** score de transparence par bailleur : complétude des champs, fraîcheur, granularité, publication sur un standard (IATI).
*Existant :* `Source` (`reliability`, `type`), métadonnées de collecte.
**À faire :** indicateurs : taux de champs renseignés sur ses lignes (0,3), publication IATI oui/non (0,2), fraîcheur moyenne (0,2), granularité projet vs agrégat (0,2), présence de descriptif (0,1).
`API` : `GET /api/institutions/{sourceId}/scores/transparency`.
Front : jauge sur la fiche institution + classement des bailleurs.
**Finition : P1.** Estim. : 0,5 j.

#### C3.7 Recommendation Engine
**Objectif :** recommander où allouer le prochain euro : liste priorisée de couples pays x secteur (fort gap + faible équité + faible risque + aligné CDN).
*Existant :* C3.1, C3.3, C2.8, C2.6.
**À faire :** combinaison pondérée des scores déjà calculés -> classement + justification par recommandation. Pas d'apprentissage : c'est une agrégation transparente des signaux C2/C3.
`API` : `GET /api/recommendations?horizon=&instrument=`.
Front : `RecommendationsPage.jsx` (liste priorisée + « pourquoi »).
**Finition : P2.** Estim. : 0,5 j.

#### C3.8 Smart Alerts
**Objectif :** alertes automatiques : nouveau gap critique, opportunité qui ferme, score qui se dégrade, pic/chute de flux anormal, nouveau financement sur un pays suivi.
*Existant :* module `Notification` (backend + cloche header + lu/non lu + navigation), Mercure.
**À faire :** moteur de règles `AlertRule` (type, seuil, cible, destinataires) évalué par une commande `app:alerts:evaluate` (DAG Airflow quotidien) -> crée des `Notification` + push Mercure. Préférences d'abonnement par utilisateur (pays/secteurs suivis).
`API` : `GET/POST /api/alert-rules`, `GET /api/me/alert-subscriptions`.
Front : réglages d'alertes dans le profil + affichage enrichi dans la cloche existante.
**Finition : P1.** Estim. : 1 j.

---

## 7. Explicitement reporté (post-sprint)

| Item | Raison | Prochaine itération |
|---|---|---|
| Modèles ML entraînés (scikit-learn / XGBoost) pour tous les scores | 2 semaines ne suffisent pas à constituer un jeu d'entraînement étiqueté fiable | Itération « C2/C3 v2 » : garder les scores règles comme référence explicable, entraîner en parallèle |
| PostGIS + géocodage sous-national (C3.2 complet) | Pas de données de localisation projet dans les sources actuelles | Connecteur enrichi + `postgis` |
| Connecteurs automatiques d'opportunités (C1.8) | Chaque bailleur a un format propre ; hors budget temps | DAG dédié par bailleur, comme B1 |
| Mapbox (C1.2) | jsvectormap déjà intégré et gratuit ; migration = coût sans valeur immédiate | Si besoin de fonds de carte détaillés |
| Durcissement production de tous les scores P2 | Priorité au périmètre fonctionnel complet | Passage P2 -> P1 -> P0 par score, piloté par l'usage réel |
| Corpus RAG large (C2.1) | Borné au corpus interne pour la fiabilité des réponses | Ajout progressif de sources externes validées |

---

## 8. Recette de fin de sprint (Definition of Done)

Par palier, avant merge sur `developp` :

1. **Tests** : suite backend verte (`-d memory_limit=512M`), tests des nouveaux endpoints, tests `ai/` (pytest) si concerné.
2. **CI GitLab réellement verte** : ouvrir les logs `phpunit` **et** `build_and_push_image` (ne pas se fier au badge — point d'attention #51).
3. **i18n** : aucun texte en dur, `fr.json` et `en.json` complets.
4. **Cas vides** : chaque écran gère « aucune donnée » (comportement B3.2 « Donnée non disponible »).
5. **Swagger** : chaque nouvel endpoint documenté (attributs `#[OA\...]`).
6. **Cohérence des chiffres** : tout agrégat recoupé avec une requête SQL de contrôle (méthode B3.4).
7. **Note de conception** committée sous `docs/superpowers/specs/` pour chaque C-tâche non triviale.
8. **README** : section « État d'avancement » mise à jour (nouveau bloc « Volet C »), + entrée dans `docs/exploitation/runbooks.md` si un service (`ai/`) ou un DAG est ajouté.
9. **Recette formelle** par palier (`docs/superpowers/specs/2026-XX-cN-recette.md`), en attente de signature du Product Owner — même circuit que A3.10 / B1.10 / B3.6.

---

## 9. Répartition synthétique de l'effort

| Bloc | Jours-homme | Dont nouveau vs réutilisation |
|---|---|---|
| Fondations (F1-F7) | ~2,5 | F5-F6 (service IA) = seul bloc vraiment neuf |
| C1 (8 items) | ~6 | ~40 % réutilise le Volet A |
| C2 (8 items) | ~6 | scoring = 1 framework (F3) réutilisé 6 fois |
| C3 (8 items) | ~5,5 | C3.7 = pure agrégation de C2/C3 |
| **Total** | **~20 jours-homme** | = 2 devs x 10 jours |

**Chemin critique :** F5-F6 (service IA) -> C2.1 / C2.7 / C3.5. À démarrer dès J0 par Dev B, en parallèle des fondations backend de Dev A.
