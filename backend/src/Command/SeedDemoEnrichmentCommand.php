<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\TenderCategory;
use App\Entity\Tender;
use App\Repository\OpportunityRepository;
use App\Repository\SectorRepository;
use App\Repository\TenderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A3.14 (2026-09-25) : seme en production les donnees de demonstration qui
 * n'existaient jusqu'ici que sur le Codespace de developpement - les 4
 * champs d'enrichissement des 9 `Opportunity` existantes (A3.12) et les 8
 * `Tender` de demonstration (A3.13). Deliberement SANS PASSER PAR
 * `doctrine:fixtures:load` : cette commande a ete ecrite en reaction
 * directe a un incident reel du meme jour ou `fixtures:load` a purge
 * ~4000 lignes `funding` reelles en developpement (voir le commentaire de
 * FundingFixtures/OpportunityFixtures/TenderFixtures pour le contexte) -
 * cette commande ne touche jamais que `opportunity`/`tender`, jamais
 * `funding`/`emission`/`user`/etc.
 *
 * Idempotente par construction, appelee a chaque demarrage du conteneur
 * (voir docker/backend/entrypoint.sh, meme raisonnement que les
 * migrations juste au-dessus) :
 * - Tender : n'insere les 8 lignes que si la table est vide au depart
 *   (COUNT(*) = 0) - jamais de doublon sur un redemarrage suivant.
 * - Opportunity : ne backfille que les lignes dont `instrument` est
 *   encore NULL, identifiees par titre exact - un titre qui ne
 *   correspond a aucune ligne existante (opportunite jamais creee sur
 *   cet environnement) est silencieusement ignore plutot que de creer
 *   une ligne inattendue.
 */
#[AsCommand(name: 'app:seed-demo-enrichment', description: 'Seme les donnees de demonstration Opportunity/Tender si absentes (idempotent)')]
final class SeedDemoEnrichmentCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OpportunityRepository $opportunityRepository,
        private readonly TenderRepository $tenderRepository,
        private readonly SectorRepository $sectorRepository,
    ) {
        parent::__construct();
    }

    /**
     * [title, instrument, applicationUrl, contactEmail, requiredDocuments].
     *
     * @var list<array{0: string, 1: FundingInstrument, 2: string, 3: string, 4: string}>
     */
    private const OPPORTUNITY_ENRICHMENT = [
        ['GCF Simplified Approval Process - Round 14', FundingInstrument::Grant,
            'https://www.greenclimate.fund/simplified-approval-process', 'sap@gcfund.org',
            'Note conceptuelle, lettre de non-objection de l\'Autorité nationale désignée, budget prévisionnel détaillé.'],
        ['AfDB Climate Action Window - Agriculture Resilience', FundingInstrument::LoanConcessional,
            'https://www.afdb.org/en/topics-and-sectors/initiatives-partnerships/climate-action-window', 'climate.action@afdb.org',
            'Fiche de concept projet, preuve de cofinancement national (≥ 20%), étude de faisabilité préliminaire.'],
        ['PROGREEN Landscape Restoration Facility', FundingInstrument::Grant,
            'https://www.worldbank.org/en/programs/progreen', 'progreen@worldbank.org',
            'Note de projet, cadre de résultats, plan de sauvegarde environnementale et sociale.'],
        ['GCF Adaptation Fund - Coastal Resilience', FundingInstrument::Grant,
            'https://www.greenclimate.fund/readiness/adaptation', 'adaptation@gcfund.org',
            'Note conceptuelle, évaluation de vulnérabilité côtière, lettre d\'endossement du point focal national.'],
        ['OPEC Fund Energy for Growth Facility', FundingInstrument::LoanConcessional,
            'https://opecfund.org/operations/energy-for-growth', 'energy@opecfund.org',
            'Dossier technique du projet, états financiers audités des 3 derniers exercices, plan d\'affaires.'],
        ['Desert to Power Initiative - Sahel Window', FundingInstrument::Guarantee,
            'https://www.afdb.org/en/desert-to-power', 'desert.to.power@afdb.org',
            'Étude de faisabilité technique, plan de raccordement au réseau régional, accord de principe des autorités locales.'],
        ['Sustainable Transport Innovation Fund', FundingInstrument::LoanNonConcessional,
            'https://www.worldbank.org/en/topic/transport/brief/sustainable-transport-innovation-fund', 'transport.innovation@worldbank.org',
            'Étude d\'impact carbone, plan de mise en œuvre sur 5 ans, garanties souveraines ou sous-souveraines.'],
        ['GreenAccess Community Grants - Local Adaptation', FundingInstrument::Grant,
            'https://greenaccess.org/community-grants', 'grants@greenaccess.org',
            'Formulaire simplifié de demande, lettre de soutien de la collectivité locale, budget sur une page.'],
        ['GCF Private Sector Facility - Blended Finance Window', FundingInstrument::Equity,
            'https://www.greenclimate.fund/psf', 'privatesector@gcfund.org',
            'Présentation investisseur (pitch deck), structure de financement mixte proposée, preuve de cofinancement privé.'],
    ];

    /**
     * [sectorName|null, title, issuingOrganization, referenceNumber, category,
     *  description, eligibilityCriteria, estimatedValue|null, publishedAt,
     *  submissionDeadline, documentsUrl, contactEmail|null].
     *
     * @var list<array{0: ?string, 1: string, 2: string, 3: ?string, 4: TenderCategory, 5: string, 6: string, 7: ?string, 8: string, 9: string, 10: string, 11: ?string}>
     */
    private const TENDERS = [
        ['Renewable Energy', 'Étude de faisabilité - Mini-réseaux solaires en zone rurale', 'Banque Africaine de Développement', 'AFDB-RFP-2026-0142', TenderCategory::Consulting,
            'Réalisation d\'une étude de faisabilité technique, financière et environnementale pour le déploiement de mini-réseaux solaires dans 25 localités rurales non raccordées au réseau national.',
            'Cabinet de conseil ou consortium justifiant d\'au moins 3 références similaires en Afrique subsaharienne dans les 5 dernières années. Chef de mission ingénieur électricien avec 10 ans d\'expérience minimum.',
            '450000.00', '2026-08-15', '2026-11-15', 'https://www.afdb.org/en/projects-and-operations/procurement', 'procurement@afdb.org'],
        ['Adaptation', 'Fourniture et installation de stations météorologiques automatiques', 'Programme des Nations Unies pour l\'Environnement', 'PNUE-GOODS-2026-088', TenderCategory::Goods,
            'Fourniture, livraison, installation et mise en service de 40 stations météorologiques automatiques reliées au réseau national d\'alerte précoce, avec formation du personnel technique.',
            'Fabricant ou distributeur agréé, garantie constructeur minimum 5 ans, service après-vente présent dans la région. Certification ISO 9001 exigée.',
            '620000.00', '2026-07-01', '2026-10-01', 'https://www.unep.org/about-un-environment/procurement', 'procurement@unep.org'],
        ['Forestry', 'Audit environnemental et social - Programme de reboisement communautaire', 'Banque Mondiale', 'WB-CONS-2026-0311', TenderCategory::Consulting,
            'Audit indépendant de conformité environnementale et sociale (cadre ESS) du programme de reboisement communautaire, incluant visite de terrain dans 8 pays et rapport final avec plan d\'action correctif.',
            'Auditeur ou cabinet accrédité, expérience avérée des cadres de sauvegarde ESS de la Banque Mondiale, indépendance vis-à-vis du projet audité.',
            '280000.00', '2026-11-01', '2027-01-31', 'https://www.worldbank.org/en/about/corporate-procurement', 'corporateprocurement@worldbank.org'],
        [null, 'Services de conseil en stratégie climatique nationale', 'Green Climate Fund', 'GCF-RFP-2026-0057', TenderCategory::Consulting,
            'Accompagnement de 6 pays bénéficiaires dans l\'actualisation de leur Contribution Déterminée au niveau National (CDN) et l\'alignement de leur stratégie de financement climatique.',
            'Cabinet international ou consortium, expérience CDN démontrée sur au moins 5 pays, équipe pluridisciplinaire (climat, finance publique, droit international).',
            null, '2026-06-01', '2026-09-15', 'https://www.greenclimate.fund/about/procurement', 'procurement@gcfund.org'],
        ['Sustainable Transport', 'Fourniture de véhicules électriques pour le transport public urbain', 'Banque Africaine de Développement', 'AFDB-GOODS-2026-0203', TenderCategory::Goods,
            'Fourniture de 60 bus électriques et de leurs infrastructures de recharge associées pour trois réseaux de transport public urbain, incluant maintenance sur 3 ans.',
            'Constructeur ou représentant agréé, autonomie minimale 250 km, pièces détachées disponibles localement, références de déploiement en Afrique ou zones climatiques comparables.',
            '18500000.00', '2026-09-01', '2027-02-28', 'https://www.afdb.org/en/projects-and-operations/procurement', 'procurement@afdb.org'],
        ['Sustainable Transport', 'Étude d\'impact carbone - Corridor de transport durable', 'Banque Mondiale', 'WB-CONS-2026-0198', TenderCategory::Consulting,
            'Évaluation quantitative de la réduction d\'émissions attendue sur le corridor de transport durable, méthodologie compatible avec les standards de crédits carbone reconnus.',
            'Expert ou cabinet certifié en méthodologies MRV (mesure, rapport, vérification) carbone, expérience de projets de transport bas-carbone.',
            '195000.00', '2026-02-01', '2026-06-30', 'https://www.worldbank.org/en/about/corporate-procurement', 'corporateprocurement@worldbank.org'],
        ['Agriculture', 'Services de suivi-évaluation - Programme agriculture climato-intelligente', 'GreenAccess Platform', 'GA-SVC-2026-014', TenderCategory::Services,
            'Mise en place d\'un dispositif de suivi-évaluation continu (collecte terrain, tableau de bord, rapports trimestriels) pour le programme régional d\'agriculture climato-intelligente.',
            'Organisation ou cabinet avec présence terrain dans au moins 4 pays du programme, expérience en collecte de données agricoles géoréférencées.',
            '135000.00', '2026-10-15', '2027-01-15', 'https://greenaccess.org/procurement', 'procurement@greenaccess.org'],
        ['Adaptation', 'Construction d\'infrastructures de gestion des eaux pluviales', 'Ministère de l\'Environnement (pays NEV, appel régional)', 'MIN-ENV-TRAV-2026-002', TenderCategory::Works,
            'Travaux de construction de bassins de rétention et de canaux de drainage pour réduire le risque d\'inondation dans 4 quartiers urbains à forte densité de population.',
            'Entreprise de travaux publics agréée localement, qualification en ouvrages hydrauliques, capacité financière justifiée (chiffre d\'affaires 3 dernières années).',
            '3200000.00', '2025-11-01', '2026-03-31', 'https://greenaccess.org/procurement/archive', null],
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->seedTenders($io);
        $this->backfillOpportunities($io);

        return Command::SUCCESS;
    }

    private function seedTenders(SymfonyStyle $io): void
    {
        if (0 !== $this->tenderRepository->count([])) {
            $io->writeln('Tender : deja peuple, rien a faire.');

            return;
        }

        foreach (self::TENDERS as [$sectorName, $title, $issuingOrganization, $referenceNumber, $category, $description, $eligibilityCriteria, $estimatedValue, $publishedAt, $submissionDeadline, $documentsUrl, $contactEmail]) {
            $sector = null === $sectorName ? null : $this->sectorRepository->findOneBy(['name' => $sectorName]);

            $tender = new Tender(
                $title,
                $issuingOrganization,
                $category,
                $description,
                $eligibilityCriteria,
                new \DateTimeImmutable($publishedAt),
                new \DateTimeImmutable($submissionDeadline),
            );
            $tender->setSector($sector);
            $tender->setReferenceNumber($referenceNumber);
            $tender->setEstimatedValue($estimatedValue);
            $tender->setDocumentsUrl($documentsUrl);
            $tender->setContactEmail($contactEmail);

            $this->entityManager->persist($tender);
        }

        $this->entityManager->flush();
        $io->writeln(\sprintf('Tender : %d lignes de demonstration inserees.', \count(self::TENDERS)));
    }

    private function backfillOpportunities(SymfonyStyle $io): void
    {
        $updated = 0;

        foreach (self::OPPORTUNITY_ENRICHMENT as [$title, $instrument, $applicationUrl, $contactEmail, $requiredDocuments]) {
            $opportunity = $this->opportunityRepository->findOneBy(['title' => $title]);
            if (null === $opportunity || null !== $opportunity->getInstrument()) {
                continue;
            }

            $opportunity->setInstrument($instrument);
            $opportunity->setApplicationUrl($applicationUrl);
            $opportunity->setContactEmail($contactEmail);
            $opportunity->setRequiredDocuments($requiredDocuments);
            ++$updated;
        }

        if ($updated > 0) {
            $this->entityManager->flush();
        }

        $io->writeln(\sprintf('Opportunity : %d ligne(s) enrichie(s) (sur %d candidates).', $updated, \count(self::OPPORTUNITY_ENRICHMENT)));
    }
}
