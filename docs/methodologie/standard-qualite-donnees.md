# Standard de nettoyage, validation, fiabilité et traçabilité des données

Document de méthodologie, ajouté le 2026-10-13 en réponse au point 5 du Prompt
Maître #3 ("détecter les doublons et définir un standard robuste de nettoyage,
validation, fiabilité et traçabilité des données"). Décrit ce qui existe déjà
dans le code (avec références précises, vérifiées en lisant le code réel, pas
supposées) et ce qui est proposé comme prochaine étape - les deux sont
distingués explicitement pour ne jamais faire passer une recommandation pour
un mécanisme déjà en place.

Indexé dans le corpus du Copilot IA (`ai/ingest.py`) au même titre que le
reste de `docs/`.

---

## 1. Nettoyage (cleaning)

**Déjà en place :**

- Chaque connecteur mappe son vocabulaire propre (codes sectoriels CRS/DAC,
  thèmes World Bank, codes IATI, marqueurs de Rio…) vers le référentiel à 5
  secteurs de NEV via une fonction dédiée (`pipeline/processors/sector_mapping*.py`)
  - jamais un `switch` ad hoc dupliqué par connecteur.
- Une ligne dont le secteur ne peut pas être mappé est **rejetée explicitement**
  (`nev.funding.rejets`, raison `unclassifiable_sector`) plutôt que devinée ou
  silencieusement classée dans un secteur par défaut - voir
  `pipeline/processors/funding_validator.py::process_message()`.
- Les montants dans une devise non-USD ne sont jamais convertis sur une
  hypothèse : `original_amount`/`original_currency`/`exchange_rate` restent
  `NULL` si aucun taux réel n'est disponible pour la devise du pays concerné,
  plutôt que d'inventer un taux (voir le commentaire de `process_message()`
  sur `SOURCES_WITHOUT_NATIVE_ORIGINAL_AMOUNT`).

## 2. Validation

**Déjà en place :**

- Chaque ligne porte un `ValidationStatus` (`demo` / `validated`), visible
  directement dans l'UI (colonne "Statut" de `DataPage.jsx`) - jamais masqué
  à l'utilisateur final.
- Une **contrainte d'unicité partielle Postgres**
  (`uniq_funding_dedup_key_current` sur `source_id+country_id+sector_id+year+funding_type`,
  restreinte à `WHERE is_current = true`) empêche structurellement l'insertion
  de deux lignes "courantes" pour la même clé métier - voir le docblock de
  `App\Entity\Funding`.
- Chaque DAG de collecte a ses propres tests (`pipeline/tests/test_dag_*.py`)
  vérifiant le comportement avant fusion.

## 3. Fiabilité (reliability)

**Déjà en place :**

- Chaque `Source` porte un niveau de fiabilité (`high`/`medium`/`low`),
  affiché publiquement sur `SourcesPage.jsx` et `InstitutionProfilePage.jsx` -
  jamais une note interne cachée.
- Un **score de transparence par bailleur** (`TransparencyScoreService`,
  `transparency.html`) combine complétude des champs, publication sur le
  registre IATI, fraîcheur de la dernière collecte et granularité - voir
  `docs/methodologie/referentiels-scores-climat-finance.md` pour le détail
  des pondérations.
- La **fraîcheur de collecte** par source est suivie et affichée
  (`GET /api/analytics/data-freshness`, bandeau d'alerte sur `DashboardPage.jsx`
  si une source n'a pas été recollectée depuis plus de 100 jours).

## 4. Traçabilité (traceability)

**Déjà en place :**

- **Historisation SCD2 complète** : une ligne n'est jamais écrasée. Toute
  révision crée une nouvelle version (`isCurrent`/`validFrom`/`validTo`),
  l'ancienne reste consultable - voir `App\Entity\Funding` et
  `pipeline/processors/funding_validator.py::upsert_funding()`.
- **`funding.external_reference`** (ajouté le 2026-10-12, voir le point 5 du
  Prompt Maître #3 ci-dessous) : identifiant du projet chez la source (ex.
  identifiant d'activité IATI), reflète la contribution la plus récente à
  cette ligne agrégée.
- **`funding_project_contribution`** : table de suivi, par projet source réel
  (`source_id`, `project_id`, `country_id`), de la dernière contribution
  connue - permet d'appliquer un delta plutôt que de resommer naïvement à
  chaque réexécution d'un connecteur (voir le docblock
  d'`apply_project_contribution()` pour l'incident de double comptage du
  2026-08-31 que ce mécanisme corrige).
- **`collectionDate`** par ligne : date réelle de collecte, distincte de
  `year` (année d'approbation/engagement du financement, pas de collecte -
  voir le docblock de `Funding::$year`).

## 5. Détection de doublons - méthode et limites (audit du 2026-10-11/12)

**Méthode appliquée** (exportées les 3996 lignes réelles via
`GET /api/funding`, analysées hors ligne) :

1. Recherche de doublons **exacts** : même `(pays, secteur, année, montant)`,
   sources différentes. **Résultat : 0.**
2. Recherche de **quasi-doublons** : même `(pays, secteur, année)`, sources
   différentes, montants à ≤5% d'écart. **Résultat : 13 paires candidates**,
   dont 11/13 suivant un profil très spécifique (AfDB vs World Bank, montant
   World Bank arrondi contre montant AfDB précis) - compatible avec un
   **cofinancement multilatéral réel rapporté indépendamment par chacune des
   deux institutions**, pas nécessairement un doublon.

**Pourquoi aucune de ces 13 paires n'a été fusionnée ou supprimée :** un
écart de montant ≤5% sur un même pays/secteur/année est **structurellement
indiscernable**, à partir des seules données agrégées, entre (a) un double
comptage du même financement et (b) deux financements réels et distincts
cofinançant le même programme - un cas courant et légitime en financement
climatique multilatéral. Sans un identifiant de projet partagé et vérifiable
entre les deux sources, toute suppression serait une **donnée potentiellement
réelle supprimée sur une simple coïncidence statistique** - contraire à la
consigne explicite de ne jamais supprimer sans preuve suffisante.

**Limite actuelle à connaître** : `external_reference` ne se remplit que
lors du **prochain passage réussi du connecteur concerné** pour chaque
ligne (il n'y a pas eu de réimport rétroactif des 3996 lignes existantes).
Les 13 paires candidates identifiées lors de cet audit peuvent donc encore
avoir `external_reference = NULL` tant que leurs DAG Airflow respectifs
n'ont pas tourné depuis le déploiement de ce champ.

## 6. Prochaines étapes proposées (non implémentées à ce jour)

Les points suivants sont des **recommandations**, pas des mécanismes déjà en
place - à prioriser avec l'équipe avant implémentation :

1. **Revue manuelle périodique des quasi-doublons** : réexécuter la méthode
   de la section 5 (ex. trimestriellement, via une commande Symfony dédiée
   plutôt qu'une analyse manuelle ponctuelle) et consigner la décision
   (cofinancement confirmé / doublon confirmé et corrigé / toujours ambigu)
   pour chaque paire candidate - avec la justification, jamais une
   suppression silencieuse.
2. **Rapprochement cross-source par `external_reference`** : une fois ce
   champ peuplé pour les 13 paires actuelles (après leur prochain passage
   pipeline), vérifier si les identifiants de projet eux-mêmes (souvent
   publiés en texte libre, ex. un numéro de programme commun cité dans la
   description IATI de chaque institution) permettent de confirmer ou
   d'infirmer un cofinancement - démarche manuelle, pas automatisable de
   façon fiable à ce stade.
3. **Alerte automatique sur nouveau quasi-doublon** : étendre
   `App\Service\AlertEvaluationService` (C3.8, déjà opérationnel pour
   `gap_critical`/`opportunity_closing`/`score_degraded`/`abnormal_flow`)
   d'un 5ᵉ type d'alerte signalant toute nouvelle paire candidate détectée
   lors d'une collecte, pour que la revue (point 1) ne dépende plus d'un
   audit manuel déclenché à la main.
