# Note de cadrage — Accès aux données GreenAccess pour NEV Climate Data

| Champ | Valeur |
|---|---|
| Destinataire | [Lead Développeur / CTO GreenAccess] |
| Entité destinataire | [Équipe Technique GreenAccess] |
| Émetteur | NEV Climate Data — Dakar, Sénégal |
| Objet | Cadrage du périmètre de données et des accès techniques pour l'intégration GreenAccess → NEV Climate Data |
| Référence projet | Tâche B2.1, plan d'implémentation NEV Climate Data — cahier des charges v2.0, sections 6.2, 6.3, 6.5, 9.2 |
| Statut | Document de travail — à valider et signer conjointement avant tout développement (cahier des charges, règle 9.2) |
| Date | 2026-08-26 |
| Version | 1.0 |

## 1. Contexte et objectif

NEV Climate Data intègre une source de données événementielle issue de l'application mobile
GreenAccess (finance verte), afin d'exposer des indicateurs agrégés (scores climat,
financements verts, assurance indicielle) sur sa plateforme, aux côtés des données collectées
depuis les sources institutionnelles (Banque Mondiale, Fonds Vert pour le Climat, BAD, PNUE).

Cette note formalise, **avant tout développement**, le périmètre exact des données
concernées, les garde-fous de confidentialité applicables, et les modalités d'accès
techniques demandées à l'équipe GreenAccess — conformément à la règle du cahier des charges
NEV Climate Data (section 9.2) : *"Coordination avec l'équipe technique GreenAccess : accès
Firebase en lecture seule, validation du périmètre exact des données agrégées autorisées."*

Aucun accès technique n'est activé et aucun développement du connecteur GreenAccess
(tâches B2.2 et suivantes) ne démarre avant la signature conjointe de cette note.

## 2. Périmètre des données demandées

Trois collections Firestore, et trois seulement, sont concernées. Aucune autre collection de
l'application GreenAccess n'est demandée.

### 2.1. `scores_climat`

| Champ | Description |
|---|---|
| Score écologique | Valeur du score climat calculé par GreenAccess |
| Niveau de risque | Classification de risque associée |
| Secteur d'activité | Secteur concerné (agriculture, énergie, etc.) |
| Pays / région | Localisation à l'échelle pays ou région uniquement |
| Horodatage | Date/heure de calcul ou de mise à jour du score |

### 2.2. `demandes_financement`

| Champ | Description |
|---|---|
| Montant sollicité | Montant demandé par le porteur de projet |
| Montant accordé | Montant effectivement approuvé, le cas échéant |
| Statut | Approuvé / rejeté / en cours |
| Type de projet vert | Catégorie du projet financé |
| Devise | Devise d'origine du montant |
| Pays / région | Localisation à l'échelle pays ou région uniquement |
| Date | Date de la demande ou de la décision |

### 2.3. `contrats_assurance`

| Champ | Description |
|---|---|
| Type d'assurance | Indicielle / paramétrique |
| Culture / secteur | Culture agricole ou secteur assuré |
| Montant souscrit | Montant de la prime ou du contrat |
| Indemnisation versée | Montant indemnisé, le cas échéant |
| Pays / région | Localisation à l'échelle pays ou région uniquement |
| Période | Période de couverture du contrat |

## 3. Exclusions strictes — garde-fous de confidentialité

Ces exclusions sont non négociables et conditionnent la validation de cette note.

**Collections explicitement exclues** : `users`, `profils_exploitants`, `paiements`, ainsi
que toute autre collection applicative de GreenAccess non listée en section 2. Aucun accès,
même en lecture, n'est demandé sur ces collections.

**Données personnelles (PII) explicitement exclues**, y compris à l'intérieur des trois
collections autorisées :

- Nom, prénom, tout identifiant nominatif
- Numéro de téléphone, adresse e-mail
- Identifiant bancaire ou Mobile Money
- Coordonnées GPS précises de parcelle — seule la granularité pays / région / zone est
  demandée, jamais une localisation individualisable

Cette exclusion correspond à l'exigence du cahier des charges NEV Climate Data (section
6.5) : *"Aucune donnée individuelle (nom, e-mail, téléphone, géolocalisation précise, contenu
d'un dossier de financement ou d'un contrat) ne doit transiter ni être stockée côté NEV
Climate Data."*

## 4. Modalités d'accès technique demandées

### 4.1. Compte de service Firebase

Un **compte de service (Service Account) dédié** à NEV Climate Data, avec des règles IAM
strictement limitées :

- Rôle **`Viewer`** (lecture seule) — aucun droit d'écriture, de suppression ou
  d'administration
- Scope restreint **aux trois collections listées en section 2** — aucun accès aux autres
  collections du projet Firebase GreenAccess

### 4.2. Déclencheurs événementiels (Cloud Functions)

Le flux événementiel repose sur des déclencheurs `onWrite` sur les trois collections
autorisées, gérés et déployés **côté GreenAccess** (dans leur projet Firebase), qui publient
vers une passerelle HTTP exposée par NEV Climate Data, elle-même relayant vers des topics
Kafka isolés (`greenaccess.scores.raw`, `greenaccess.financements.raw`,
`greenaccess.assurance.raw`) — architecture détaillée dans le document technique
`docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`.

En complément, une synchronisation batch quotidienne (lecture seule sur l'API Firestore REST,
avec le même compte de service) sert de filet de sécurité en cas d'évènement manqué — elle
n'introduit aucun accès supplémentaire au-delà de celui décrit en 4.1.

## 5. Engagements NEV Climate Data

- Les données reçues sont **agrégées par pays/secteur/période et anonymisées avant toute
  écriture** dans les systèmes NEV Climate Data — aucune donnée brute individuelle n'est
  jamais persistée côté NEV.
- Un **audit de conformité** (tâche B2.7 du plan d'implémentation) est réalisé avant mise en
  production du connecteur, vérifiant explicitement l'absence de toute donnée individuelle
  dans les topics Kafka, les tables TimescaleDB et les réponses de l'API NEV Climate Data.
- Le compte de service et les topics Kafka dédiés à ce flux sont **isolés** des autres projets
  hébergés sur la même infrastructure technique (cahier des charges, section 6.5).

## 6. Validation

Ce document doit être relu, complété (destinataire nominatif) et validé conjointement avant
toute activation d'accès technique ou tout développement du connecteur GreenAccess.

| | NEV Climate Data | GreenAccess |
|---|---|---|
| Nom | | |
| Fonction | | |
| Date | | |
| Signature | | |
