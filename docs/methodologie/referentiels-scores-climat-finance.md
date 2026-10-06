# Référentiels réels des scores climat-finance de NEV Climate Data

Document de méthodologie, ajouté le 2026-10-06 à la demande explicite de
l'utilisateur, avant la mise en place de la vente de rapports payants :
connaître la formule d'un score ne suffit pas à le rendre crédible auprès
d'un client institutionnel - il faut pouvoir dire sur quelle norme ou
autorité de régulation climatique reconnue elle s'appuie. Les cinq scores
calculés par la plateforme (bancabilité, risque d'investissement, équité,
transparence, alignement CDN) restent des indicateurs ouverts calculés sur
des données réelles, jamais une reproduction d'un référentiel tiers -
chaque section ci-dessous précise le lien exact entre la méthode et le
référentiel qu'elle recoupe, pas une équivalence stricte.

Indexé dans le corpus du Copilot IA (ai/ingest.py) au même titre que le
reste de docs/ : une question sur la base de ces scores peut désormais
être répondue avec une vraie citation.

## Score de bancabilité (bankability)

Le score de bancabilité de NEV Climate Data recoupe le **Cadre
d'investissement du Fonds Vert pour le Climat (Green Climate Fund, GCF)**,
qui définit 6 critères officiels : Impact Potential, Paradigm-shift
Potential, Sustainable Development Potential, Needs of the Recipient,
Country Ownership, Efficiency and Effectiveness. Référence officielle :
https://www.greenclimate.fund/about/policies/investment

Les 5 indicateurs pondérés du score (complétude des données, porteur de
projet identifié, cohérence montant/secteur, instrument de financement
réaliste pour le pays, risque pays inversé) recoupent directement les
dimensions "Needs of the Recipient", "Country Ownership" et "Efficiency
and Effectiveness" de ce cadre - sans en reprendre la grille d'évaluation
complète (le GCF évalue un dossier de candidature complet, NEV Climate
Data évalue un profil de projet à partir de données structurées).

## Score de risque d'investissement (investment_risk)

Ce score s'appuie sur la logique des **Equator Principles (EP4)**
(https://equator-principles.com/), le cadre de référence utilisé par les
institutions financières pour évaluer le risque environnemental, social
et financier d'un projet, et sur les **Normes de performance
environnementale et sociale de l'IFC**
(https://www.ifc.org/PerformanceStandards), référentiel complémentaire
pour le risque projet en finance climatique dans les pays en
développement.

Choix méthodologique assumé et documenté dans le code depuis sa
conception : aucun indice souverain tiers payant (Moody's, S&P, Coface...)
n'est utilisé. Le risque pays, secteur et projet sont calculés uniquement
à partir de données réelles ouvertes (volatilité des flux de financement,
concentration des bailleurs via l'indice de Herfindahl-Hirschman, écart
entre besoin CDN déclaré et financement reçu). C'est une limite de
couverture assumée, pas une lacune méthodologique cachée.

## Score d'équité (equity)

L'indice de vulnérabilité climatique utilisé par ce score provient du
**ND-GAIN Country Index** (Notre Dame Global Adaptation Initiative),
https://gain.nd.edu/ - l'un des indices de vulnérabilité climatique les
plus largement cités au niveau international.

Le principe mesuré - à financement par habitant égal, un pays plus
vulnérable doit être considéré comme moins bien servi qu'un pays moins
vulnérable - opérationnalise directement le principe de **responsabilité
commune mais différenciée** inscrit dans l'Accord de Paris
(https://unfccc.int/process-and-meetings/the-paris-agreement). Le
coefficient de concentration (indice de Gini) sur la répartition globale
des financements entre pays est une mesure statistique standard, non
spécifique au climat.

## Score de transparence (transparency)

Un des 5 indicateurs de ce score vérifie directement la publication des
données du bailleur selon la **norme IATI** (International Aid
Transparency Initiative), https://iatistandard.org/ - la norme de
référence internationale pour la transparence de l'aide et du financement
du développement.

Les dimensions fraîcheur, granularité et complétude des données
évaluées par ce score correspondent aux quatre piliers (Gouvernance,
Stratégie, Gestion des risques, Métriques et cibles) de la norme
**IFRS S2 - Climate-related Disclosures**, publiée par l'ISSB en
succession des recommandations de la TCFD (Task Force on Climate-related
Financial Disclosures, dissoute en 2023) :
https://www.ifrs.org/issued-standards/ifrs-sustainability-standards-navigator/ifrs-s2-climate-related-disclosures/

## Score d'alignement CDN (ndc_alignment)

Ce score compare directement le financement climatique reçu par un pays
à sa **Contribution Déterminée Nationale (CDN)** officielle au titre de
l'Accord de Paris, Article 9 (obligations de financement climatique) :
https://unfccc.int/process-and-meetings/the-paris-agreement

Les cibles CDN utilisées sont vérifiées contre le **registre officiel des
CDN de la CCNUCC** : https://unfccc.int/NDCREG - voir le commentaire de
`backend/src/DataFixtures/NdcTargetFixtures.php` pour la liste des pays
couverts et la date de dernière vérification.

## Limites à garder présentes à l'esprit

Ces scores sont des indicateurs ouverts construits par NEV Climate Data,
pas une certification délivrée par le GCF, l'IFC, l'ISSB ou la CCNUCC -
aucune de ces institutions n'a validé la méthodologie de calcul elle-même.
Le lien est conceptuel (les mêmes dimensions sont évaluées) et
statistique (données réelles, méthodes citables), pas une labellisation
officielle. La couverture géographique de certaines données sous-jacentes
(CDN, indice de vulnérabilité) reste partielle - voir les fixtures
correspondantes pour le détail exact des pays couverts.
