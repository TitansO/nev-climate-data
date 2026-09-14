<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Country;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\ProjectPipelineStage;
use App\Entity\Project;
use App\Entity\Sector;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * F4 (fondation du sprint C1-C3) : jeu de démonstration de projets à la
 * recherche de financement - sert C1.8, C2.2, C2.3, C2.4, C2.5. Aucune
 * source réelle de projets n'existe encore (voir le document de
 * référence, §7 "Reporté post-sprint") : ces 12 lignes sont explicitement
 * fictives, un par (pays, secteur) choisi pour couvrir chaque stade du
 * pipeline (idea/scoping/structuring/fundraising/financed/rejected) et
 * chaque instrument recherché au moins une fois.
 */
final class ProjectFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * [isoCode, sectorSlug, title, promoter, amountSought, instrument, stage].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: ?FundingInstrument, 6: ProjectPipelineStage}>
     */
    private const PROJECTS = [
        ['SEN', 'renewable-energy', 'Off-grid Solar Mini-Grids for Rural Casamance', "Agence Sénégalaise d'Électrification Rurale", '4500000.00', FundingInstrument::Grant, ProjectPipelineStage::Scoping],
        ['KEN', 'renewable-energy', 'Geothermal Expansion - Menengai Phase III', 'Kenya Electricity Generating Company', '18000000.00', FundingInstrument::LoanConcessional, ProjectPipelineStage::Structuring],
        ['NGA', 'sustainable-transport', 'Lagos Bus Rapid Transit Electrification', 'Lagos Metropolitan Area Transport Authority', '12000000.00', FundingInstrument::LoanConcessional, ProjectPipelineStage::Fundraising],
        ['MAR', 'sustainable-transport', 'Casablanca-Rabat Rail Corridor Efficiency Upgrade', 'ONCF', '25000000.00', FundingInstrument::LoanNonConcessional, ProjectPipelineStage::Idea],
        ['ETH', 'agriculture', 'Climate-Smart Coffee Value Chain Resilience', 'Ethiopian Coffee and Tea Authority', '3200000.00', FundingInstrument::Grant, ProjectPipelineStage::Scoping],
        ['GHA', 'forestry', 'Cocoa-Forest Landscape Restoration Initiative', 'Forestry Commission of Ghana', '6800000.00', FundingInstrument::Grant, ProjectPipelineStage::Structuring],
        ['TZA', 'adaptation', 'Coastal Community Flood Early-Warning Network', 'Tanzania Meteorological Authority', '2100000.00', FundingInstrument::Grant, ProjectPipelineStage::Financed],
        ['ZMB', 'renewable-energy', 'Kafue Gorge Floating Solar Pilot', 'ZESCO Limited', '9500000.00', FundingInstrument::Equity, ProjectPipelineStage::Idea],
        ['CIV', 'agriculture', 'Smallholder Irrigation and Drought Resilience', "Ministère de l'Agriculture", '5400000.00', FundingInstrument::LoanConcessional, ProjectPipelineStage::Fundraising],
        ['RWA', 'forestry', 'National Agroforestry Carbon Sequestration Programme', 'Rwanda Forestry Authority', '4000000.00', FundingInstrument::ResultsBased, ProjectPipelineStage::Scoping],
        ['SEN', 'adaptation', 'Saint-Louis Coastal Protection and Relocation', "Ministère de l'Environnement", '15000000.00', FundingInstrument::Grant, ProjectPipelineStage::Rejected],
        ['EGY', 'renewable-energy', 'Benban Solar Complex Battery Storage Retrofit', 'New and Renewable Energy Authority', '22000000.00', FundingInstrument::Guarantee, ProjectPipelineStage::Idea],
    ];

    public static function reference(int $index): string
    {
        return 'project_'.$index;
    }

    public function getDependencies(): array
    {
        return [CountryFixtures::class, SectorFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::PROJECTS as $index => [$isoCode, $sectorSlug, $title, $promoter, $amountSought, $instrument, $stage]) {
            /** @var Country $country */
            $country = $this->getReference(CountryFixtures::countryReference($isoCode), Country::class);
            /** @var Sector $sector */
            $sector = $this->getReference(SectorFixtures::sectorReference($sectorSlug), Sector::class);

            $project = new Project(
                $title,
                $country,
                $sector,
                $amountSought,
                \sprintf(
                    'Projet de démonstration (F4) - %s, secteur %s. Porté par %s. Aucune donnée réelle : ce projet n\'existe dans aucune source externe.',
                    $country->getName(),
                    $sector->getName(),
                    $promoter,
                ),
                $promoter,
            );
            $project->setInstrumentSought($instrument);
            $project->setPipelineStage($stage);

            $manager->persist($project);
            $this->addReference(self::reference($index), $project);
        }

        $manager->flush();
    }
}
