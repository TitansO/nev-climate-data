<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Opportunity;
use App\Entity\Sector;
use App\Entity\Source;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * F4 (fondation du sprint C1-C3) : jeu de démonstration d'appels à
 * projets - sert C1.8 (Opportunity Radar) et C2.2 (Matching Engine).
 * Saisie manuelle explicitement fictive (voir le document de référence,
 * §7 "Reporté post-sprint" : pas de connecteur automatique par bailleur
 * dans ce sprint) - dates choisies pour couvrir les 3 cas d'affichage du
 * radar : déjà fermée, ouverte (avec échéance proche ou lointaine), et
 * pas encore ouverte.
 */
final class OpportunityFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * [sourceSlug, sectorSlug|null, title, criteria, envelopeAmount, opensAt, closesAt,
     *  instrument, applicationUrl, contactEmail, requiredDocuments].
     *
     * A3.12 (2026-09-25) : quatre dernières colonnes ajoutées pour enrichir la fiche
     * au-delà du texte libre d'éligibilité (demande explicite "maximum d'informations
     * possibles") - valeurs plausibles construites à partir du programme réel que
     * chaque ligne représente, jamais inventées au hasard.
     *
     * @var list<array{0: string, 1: ?string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: FundingInstrument, 8: string, 9: string, 10: string}>
     */
    private const OPPORTUNITIES = [
        ['gcf-iati-datastore', 'renewable-energy', 'GCF Simplified Approval Process - Round 14',
            'Pays éligibles : pays NEV à faible revenu ou revenu intermédiaire inférieur. Instruments proposés : subvention, prêt concessionnel. Plafond par projet : 10 000 000 USD.',
            '50000000.00', '2026-06-01', '2026-11-30', FundingInstrument::Grant,
            'https://www.greenclimate.fund/simplified-approval-process',
            'sap@gcfund.org',
            'Note conceptuelle, lettre de non-objection de l\'Autorité nationale désignée, budget prévisionnel détaillé.'],
        ['afdb-iati-datastore', 'agriculture', 'AfDB Climate Action Window - Agriculture Resilience',
            'Pays éligibles : tous pays membres régionaux de la BAD. Instruments proposés : prêt concessionnel, garantie. Cofinancement national attendu ≥ 20%.',
            '30000000.00', '2026-08-01', '2027-01-31', FundingInstrument::LoanConcessional,
            'https://www.afdb.org/en/topics-and-sectors/initiatives-partnerships/climate-action-window',
            'climate.action@afdb.org',
            'Fiche de concept projet, preuve de cofinancement national (≥ 20%), étude de faisabilité préliminaire.'],
        ['world-bank-api', 'forestry', 'PROGREEN Landscape Restoration Facility',
            'Pays éligibles : pays éligibles IDA. Instruments proposés : subvention. Fenêtre déjà close - conservée à titre de référence historique.',
            '20000000.00', '2026-03-01', '2026-08-31', FundingInstrument::Grant,
            'https://www.worldbank.org/en/programs/progreen',
            'progreen@worldbank.org',
            'Note de projet, cadre de résultats, plan de sauvegarde environnementale et sociale.'],
        ['gcf-iati-datastore', 'adaptation', 'GCF Adaptation Fund - Coastal Resilience',
            'Pays éligibles : petits États insulaires et pays côtiers vulnérables. Instruments proposés : subvention, prêt concessionnel. Fenêtre pas encore ouverte.',
            '40000000.00', '2026-10-01', '2027-03-31', FundingInstrument::Grant,
            'https://www.greenclimate.fund/readiness/adaptation',
            'adaptation@gcfund.org',
            'Note conceptuelle, évaluation de vulnérabilité côtière, lettre d\'endossement du point focal national.'],
        ['opec-fund-climate-finance-pdf', 'renewable-energy', 'OPEC Fund Energy for Growth Facility',
            'Pays éligibles : pays à revenu faible ou intermédiaire hors OPEP. Instruments proposés : prêt concessionnel. Plafond par projet : 15 000 000 USD.',
            '15000000.00', '2026-05-15', '2026-10-15', FundingInstrument::LoanConcessional,
            'https://opecfund.org/operations/energy-for-growth',
            'energy@opecfund.org',
            'Dossier technique du projet, états financiers audités des 3 derniers exercices, plan d\'affaires.'],
        ['afdb-iati-datastore', 'renewable-energy', 'Desert to Power Initiative - Sahel Window',
            'Pays éligibles : les 11 pays du G5 Sahel et zone Desert to Power. Instruments proposés : prêt concessionnel, garantie, equity.',
            '60000000.00', '2026-01-01', '2026-12-31', FundingInstrument::Guarantee,
            'https://www.afdb.org/en/desert-to-power',
            'desert.to.power@afdb.org',
            'Étude de faisabilité technique, plan de raccordement au réseau régional, accord de principe des autorités locales.'],
        ['world-bank-api', 'sustainable-transport', 'Sustainable Transport Innovation Fund',
            'Pays éligibles : tous pays IDA/BIRD éligibles. Instruments proposés : prêt concessionnel, prêt non concessionnel. Vient d\'ouvrir.',
            '25000000.00', '2026-09-01', '2027-02-28', FundingInstrument::LoanNonConcessional,
            'https://www.worldbank.org/en/topic/transport/brief/sustainable-transport-innovation-fund',
            'transport.innovation@worldbank.org',
            'Étude d\'impact carbone, plan de mise en œuvre sur 5 ans, garanties souveraines ou sous-souveraines.'],
        ['greenaccess-events', 'adaptation', 'GreenAccess Community Grants - Local Adaptation',
            'Pays éligibles : tous pays NEV. Instruments proposés : subvention uniquement. Petits projets communautaires, plafond 200 000 USD. Ferme bientôt.',
            '2000000.00', '2026-07-01', '2026-09-30', FundingInstrument::Grant,
            'https://greenaccess.org/community-grants',
            'grants@greenaccess.org',
            'Formulaire simplifié de demande, lettre de soutien de la collectivité locale, budget sur une page.'],
        ['gcf-iati-datastore', null, 'GCF Private Sector Facility - Blended Finance Window',
            'Pays éligibles : tous pays NEV. Instruments proposés : equity, prêt non concessionnel, garantie. Secteur ouvert (multi-sectoriel), cofinancement privé requis.',
            '35000000.00', '2026-04-01', '2026-12-15', FundingInstrument::Equity,
            'https://www.greenclimate.fund/psf',
            'privatesector@gcfund.org',
            'Présentation investisseur (pitch deck), structure de financement mixte proposée, preuve de cofinancement privé.'],
    ];

    public static function reference(int $index): string
    {
        return 'opportunity_'.$index;
    }

    public function getDependencies(): array
    {
        return [SourceFixtures::class, SectorFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::OPPORTUNITIES as $index => [$sourceSlug, $sectorSlug, $title, $criteria, $envelopeAmount, $opensAt, $closesAt, $instrument, $applicationUrl, $contactEmail, $requiredDocuments]) {
            /** @var Source $source */
            $source = $this->getReference(SourceFixtures::sourceReference($sourceSlug), Source::class);
            $sector = null === $sectorSlug ? null : $this->getReference(SectorFixtures::sectorReference($sectorSlug), Sector::class);

            $opportunity = new Opportunity($title, $source, $criteria, $envelopeAmount);
            $opportunity->setSector($sector);
            $opportunity->setApplicationWindowOpensAt(new \DateTimeImmutable($opensAt));
            $opportunity->setApplicationWindowClosesAt(new \DateTimeImmutable($closesAt));
            $opportunity->setInstrument($instrument);
            $opportunity->setApplicationUrl($applicationUrl);
            $opportunity->setContactEmail($contactEmail);
            $opportunity->setRequiredDocuments($requiredDocuments);

            $manager->persist($opportunity);
            $this->addReference(self::reference($index), $opportunity);
        }

        $manager->flush();
    }
}
