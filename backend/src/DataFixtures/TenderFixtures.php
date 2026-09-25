<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Enum\TenderCategory;
use App\Entity\Sector;
use App\Entity\Tender;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * A3.13 : jeu de démonstration d'appels d'offres (marchés de prestation) -
 * jumeau de OpportunityFixtures (F4, "appels à projets") pour le nouveau
 * "Tender Radar". Saisie manuelle explicitement fictive, mêmes 3 cas
 * d'affichage que OpportunityFixtures : déjà fermé, ouvert, pas encore
 * publié. `issuingOrganization` mélange volontairement bailleurs déjà
 * suivis comme `Source` (BAD, GCF, Banque Mondiale - qui émettent aussi de
 * vrais appels d'offres, pas seulement des appels à projets) et d'autres
 * émetteurs réalistes qui n'ont aucune raison d'être une `Source` de ce
 * projet (ministère national, plateforme partenaire) - illustre
 * concrètement pourquoi ce champ est un texte libre (voir Tender::
 * $issuingOrganization).
 */
final class TenderFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * [sectorSlug|null, title, issuingOrganization, referenceNumber, category,
     *  description, eligibilityCriteria, estimatedValue|null, publishedAt,
     *  submissionDeadline, documentsUrl, contactEmail].
     *
     * @var list<array{0: ?string, 1: string, 2: string, 3: ?string, 4: TenderCategory, 5: string, 6: string, 7: ?string, 8: string, 9: string, 10: string, 11: string}>
     */
    private const TENDERS = [
        ['renewable-energy', 'Étude de faisabilité - Mini-réseaux solaires en zone rurale', 'Banque Africaine de Développement', 'AFDB-RFP-2026-0142', TenderCategory::Consulting,
            'Réalisation d\'une étude de faisabilité technique, financière et environnementale pour le déploiement de mini-réseaux solaires dans 25 localités rurales non raccordées au réseau national.',
            'Cabinet de conseil ou consortium justifiant d\'au moins 3 références similaires en Afrique subsaharienne dans les 5 dernières années. Chef de mission ingénieur électricien avec 10 ans d\'expérience minimum.',
            '450000.00', '2026-08-15', '2026-11-15',
            'https://www.afdb.org/en/projects-and-operations/procurement', 'procurement@afdb.org'],
        ['adaptation', 'Fourniture et installation de stations météorologiques automatiques', 'Programme des Nations Unies pour l\'Environnement', 'PNUE-GOODS-2026-088', TenderCategory::Goods,
            'Fourniture, livraison, installation et mise en service de 40 stations météorologiques automatiques reliées au réseau national d\'alerte précoce, avec formation du personnel technique.',
            'Fabricant ou distributeur agréé, garantie constructeur minimum 5 ans, service après-vente présent dans la région. Certification ISO 9001 exigée.',
            '620000.00', '2026-07-01', '2026-10-01',
            'https://www.unep.org/about-un-environment/procurement', 'procurement@unep.org'],
        ['forestry', 'Audit environnemental et social - Programme de reboisement communautaire', 'Banque Mondiale', 'WB-CONS-2026-0311', TenderCategory::Consulting,
            'Audit indépendant de conformité environnementale et sociale (cadre ESS) du programme de reboisement communautaire, incluant visite de terrain dans 8 pays et rapport final avec plan d\'action correctif.',
            'Auditeur ou cabinet accrédité, expérience avérée des cadres de sauvegarde ESS de la Banque Mondiale, indépendance vis-à-vis du projet audité.',
            '280000.00', '2026-11-01', '2027-01-31',
            'https://www.worldbank.org/en/about/corporate-procurement', 'corporateprocurement@worldbank.org'],
        [null, 'Services de conseil en stratégie climatique nationale', 'Green Climate Fund', 'GCF-RFP-2026-0057', TenderCategory::Consulting,
            'Accompagnement de 6 pays bénéficiaires dans l\'actualisation de leur Contribution Déterminée au niveau National (CDN) et l\'alignement de leur stratégie de financement climatique.',
            'Cabinet international ou consortium, expérience CDN démontrée sur au moins 5 pays, équipe pluridisciplinaire (climat, finance publique, droit international).',
            null, '2026-06-01', '2026-09-15',
            'https://www.greenclimate.fund/about/procurement', 'procurement@gcfund.org'],
        ['sustainable-transport', 'Fourniture de véhicules électriques pour le transport public urbain', 'Banque Africaine de Développement', 'AFDB-GOODS-2026-0203', TenderCategory::Goods,
            'Fourniture de 60 bus électriques et de leurs infrastructures de recharge associées pour trois réseaux de transport public urbain, incluant maintenance sur 3 ans.',
            'Constructeur ou représentant agréé, autonomie minimale 250 km, pièces détachées disponibles localement, références de déploiement en Afrique ou zones climatiques comparables.',
            '18500000.00', '2026-09-01', '2027-02-28',
            'https://www.afdb.org/en/projects-and-operations/procurement', 'procurement@afdb.org'],
        ['sustainable-transport', 'Étude d\'impact carbone - Corridor de transport durable', 'Banque Mondiale', 'WB-CONS-2026-0198', TenderCategory::Consulting,
            'Évaluation quantitative de la réduction d\'émissions attendue sur le corridor de transport durable, méthodologie compatible avec les standards de crédits carbone reconnus.',
            'Expert ou cabinet certifié en méthodologies MRV (mesure, rapport, vérification) carbone, expérience de projets de transport bas-carbone.',
            '195000.00', '2026-02-01', '2026-06-30',
            'https://www.worldbank.org/en/about/corporate-procurement', 'corporateprocurement@worldbank.org'],
        ['agriculture', 'Services de suivi-évaluation - Programme agriculture climato-intelligente', 'GreenAccess Platform', 'GA-SVC-2026-014', TenderCategory::Services,
            'Mise en place d\'un dispositif de suivi-évaluation continu (collecte terrain, tableau de bord, rapports trimestriels) pour le programme régional d\'agriculture climato-intelligente.',
            'Organisation ou cabinet avec présence terrain dans au moins 4 pays du programme, expérience en collecte de données agricoles géoréférencées.',
            '135000.00', '2026-10-15', '2027-01-15',
            'https://greenaccess.org/procurement', 'procurement@greenaccess.org'],
        ['adaptation', 'Construction d\'infrastructures de gestion des eaux pluviales', 'Ministère de l\'Environnement (pays NEV, appel régional)', 'MIN-ENV-TRAV-2026-002', TenderCategory::Works,
            'Travaux de construction de bassins de rétention et de canaux de drainage pour réduire le risque d\'inondation dans 4 quartiers urbains à forte densité de population.',
            'Entreprise de travaux publics agréée localement, qualification en ouvrages hydrauliques, capacité financière justifiée (chiffre d\'affaires 3 dernières années).',
            '3200000.00', '2025-11-01', '2026-03-31',
            'https://greenaccess.org/procurement/archive', null],
    ];

    public static function reference(int $index): string
    {
        return 'tender_'.$index;
    }

    public function getDependencies(): array
    {
        return [SectorFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::TENDERS as $index => [$sectorSlug, $title, $issuingOrganization, $referenceNumber, $category, $description, $eligibilityCriteria, $estimatedValue, $publishedAt, $submissionDeadline, $documentsUrl, $contactEmail]) {
            $sector = null === $sectorSlug ? null : $this->getReference(SectorFixtures::sectorReference($sectorSlug), Sector::class);

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

            $manager->persist($tender);
            $this->addReference(self::reference($index), $tender);
        }

        $manager->flush();
    }
}
