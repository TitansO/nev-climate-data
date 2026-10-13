# Audit d'utilité métier par page - NEV Climate Finance

Document de méthodologie, ajouté le 2026-10-13 en réponse au point 7 du Prompt
Maître #3 ("réévaluer chaque page, graphique et indicateur selon son utilité
métier, sa pertinence et sa contribution à la prise de décision"). Les 27
pages réelles du frontend ont été lues intégralement (pas seulement
survolées) pour ce verdict - voir la colonne "Preuve" pour ce qui a
effectivement été vérifié dans le code, pas supposé.

Verdict à 4 valeurs : **Garder** (valeur confirmée, rien à changer) /
**Garder + action** (valeur confirmée, mais une limite concrète identifiée) /
**Fusionner** (redondant avec une autre page/section) / **Retirer**
(aucune valeur métier identifiée). Aucune page n'a reçu le verdict
"Retirer" lors de cet audit - voir la section 2 pour pourquoi, et la
section 3 pour les fusions déjà faites au niveau composant (pas page).

## 1. Pages analytiques et décisionnelles

| Page | Valeur métier | Verdict | Preuve / limite |
|---|---|---|---|
| `DashboardPage.jsx` | Vue d'ensemble + liens directs vers les 4 outils de décision ("L'argent atteint-il la bonne cible ?") | Garder | Déjà amélioré (point 2, point 4) |
| `DataPage.jsx` | Accès aux données brutes, filtrable, exportable | Garder | Déjà amélioré (disclaimer corrigé, filtres actifs retirés) |
| `GapDetectorPage.jsx` | Écart besoin CDN déclaré vs reçu, par pays/secteur | **Garder + action** | UI et donnée réelle solides, mais seuls **2 pays sur 54** ont un besoin CDN chiffré (`NdcTargetFixtures`, confirmé en lisant le code) - l'outil fonctionne, mais sa couverture réelle est très faible aujourd'hui. **Action recommandée : enrichir les cibles CDN en priorité, pas retravailler cette page.** |
| `EquityScorePage.jsx` | Classement équité + indice de Gini, méthodologie pondérée affichée | Garder | Calculé sur tous les pays réellement financés, pas seulement ceux classés |
| `RecommendationsPage.jsx` | Classement priorisé combinant 4 signaux (écart CDN, équité, alignement CDN, sécurité investissement) | Garder | Transparent (moyenne pondérée affichée), pas une boîte noire IA |
| `CountryProfilePage.jsx` | Alignement CDN + risque investissement + part locale, par pays | **Garder + action** | Même limite de couverture CDN que GapDetector pour le volet alignement |
| `InstitutionProfilePage.jsx` | Portefeuille + score de transparence, par bailleur | Garder | Due diligence bailleur réelle |
| `TransparencyPage.jsx` | Classement transparence de tous les bailleurs | Garder | Méthodologie à 5 critères pondérés, affichée |
| `InstrumentsPage.jsx` | Répartition + croisements instrument × secteur/pays | Garder | Les 12 combinaisons les plus significatives, pas une matrice illisible |
| `BlendedFinancePage.jsx` | Simulateur de levier public/privé | Garder | Outil de structuration réel, pas un gadget |
| `ImpactCalculatorPage.jsx` | Estimation d'impact pré-investissement, par secteur/montant | Garder | Honnête sur ses limites (fourchette, pas un modèle entraîné, pas de facteur pays) |

## 2. Pages opérationnelles (appels à projets / consultance)

| Page | Valeur métier | Verdict | Preuve / limite |
|---|---|---|---|
| `TendersPage.jsx` | Appels à consultance - opportunités de prestation réelles | Garder | Déjà amélioré (point 6) |
| `OpportunityRadarPage.jsx` | Appels à projets - opportunités de financement réelles | **Garder + action** | Distinct de TendersPage par conception (payé pour prester vs financé pour son projet), pas redondant. **Gap mineur non corrigé** : pas de filtre "secteur" bien que le backend le supporte déjà (`?sector=`) - juste parce qu'aucun endpoint léger n'expose `Sector.id` à côté du nom pour peupler un menu (même gap que `SearchResultsPage.jsx`, voir sa propre docblock) |
| `ProjectPipelinePage.jsx` | Suivi Kanban des candidatures d'un porteur de projet | **Garder + action** | Fonctionnalité réelle (glisser-déposer + repli clavier accessible), mais l'écriture `PATCH /api/projects/{id}` reste "implémenté non validé" depuis l'audit précédent - à vérifier en priorité, pas un problème de conception |

## 3. Pages de contenu et de confiance

| Page | Valeur métier | Verdict | Preuve / limite |
|---|---|---|---|
| `ReportsPage.jsx` | Bibliothèque de 21 rapports réels | Garder | Déjà corrigé (disclaimer obsolète) |
| `SourcesPage.jsx` | Transparence sur l'origine des données | Garder | Alimenté dynamiquement depuis `GET /api/public/institutions`, jamais une liste codée en dur |
| `SearchResultsPage.jsx` | Recherche transversale (pays/secteurs/sources/rapports/projets/appels) | Garder | Même gap mineur que OpportunityRadarPage (pas de filtre secteur) |
| `IndexPage.jsx` | Page d'accueil / orientation | Garder | Chiffres réels (API), jamais statiques |
| `AboutPage.jsx` | Mission, vision, contexte | Garder | Communication institutionnelle, utilité différente mais légitime |
| `ApiDocsPage.jsx` | Documentation développeur | Garder | Utilité développeur, pas analytique |

## 4. Pages de compte / authentification

`LoginPage.jsx`, `RegisterPage.jsx`, `AccountProfilePage.jsx`,
`AccountApiKeysPage.jsx`, `AccountUsersPage.jsx`, `NotificationsPage.jsx`,
`NotFoundPage.jsx` : infrastructure nécessaire (auth, gestion de compte,
clés API, alertes C3.8), aucune n'a de valeur analytique propre à évaluer -
**Garder** sans réserve, aucune redondance identifiée entre elles.

## 5. Ce qui a déjà été fusionné/simplifié (niveau composant, pas page)

- `DashboardPage.jsx` : donut "Répartition par type de financement" fusionné
  avec le graphique "Financement par année" (même décomposition
  bilatéral/privé/multilatéral affichée deux fois) - voir le commit du point 4.
- `DataPage.jsx` : bloc "Filtres actifs" retiré (redondant avec les
  sélecteurs de filtre eux-mêmes) - voir le commit du point 4.

## 6. Pourquoi aucune page n'a reçu le verdict "Retirer"

Les candidats les plus proches d'une redondance apparente étaient
`GapDetectorPage`/`EquityScorePage`/`RecommendationsPage` (les trois
analysent l'adéquation du financement climatique par pays) - mais
`RecommendationsPage` **synthétise** explicitement des signaux issus des
deux autres dans un classement unique pondéré, sans dupliquer leur
fonction : un lecteur qui veut seulement "où prioriser" utilise
Recommendations, un lecteur qui veut comprendre **pourquoi** un pays est
prioritaire va sur Gap Detector ou Equity Score pour le détail. Ce sont
trois profondeurs d'analyse différentes sur le même sujet, pas trois pages
redondantes.
