<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Country;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Project;
use App\Entity\Sector;
use App\Entity\Source;
use App\Service\InvestmentRiskScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C2.8 : InvestmentRiskScoreService - premier test jamais écrit pour ce
 * score, malgré 2 endpoints publics déjà en production (audit du
 * 2026-09-23). Couvre les deux méthodes publiques (scoreForCountry,
 * scoreForProject) et les deux branches de CountryRiskCalculator/
 * SectorRiskCalculator (repli neutre "aucune donnée" vs composante réelle).
 */
final class InvestmentRiskScoreServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testScoreForCountryWithNoRealDataFallsBackToTheNeutralValue(): void
    {
        $country = new Country('Uganda', 'UGA', "Afrique de l'Est");
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        $service = static::getContainer()->get(InvestmentRiskScoreService::class);
        $score = $service->scoreForCountry($country);

        // Aucune composante reelle (CountryRiskCalculator retombe sur son
        // "no_data" neutre, 0.5) -> un seul indicateur invertedIndicators,
        // poids 1.0, valeur 1-0.5=0.5 -> 50.0, grade C.
        self::assertSame(50.0, $score->getValue());
        self::assertSame('C', $score->getGrade());
    }

    public function testScoreForCountryWithASingleDonorScoresMaximumRisk(): void
    {
        $country = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $sector = new Sector('Renewable Energy');
        $source = new Source('Only Donor', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);
        // Une seule annee (flow_volatility non evaluable, <2 ans) aupres
        // d'un seul bailleur (donor_concentration = HHI = 1.0, risque max).
        $this->entityManager->persist(new Funding($country, $sector, 2024, '1000.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $service = static::getContainer()->get(InvestmentRiskScoreService::class);
        $score = $service->scoreForCountry($country);

        // Seule composante disponible : donor_concentration = 1.0 (risque
        // max, un seul bailleur) -> indicateur inverse = 1-1.0=0.0 -> 0.0, grade E.
        self::assertSame(0.0, $score->getValue());
        self::assertSame('E', $score->getGrade());
    }

    public function testScoreForProjectWithNoRealDataAndDefaultStageScoresLow(): void
    {
        $country = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $sector = new Sector('Agriculture');
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);
        // pipelineStage jamais fixe explicitement -> defaut ProjectPipelineStage::Idea
        // (le stade le plus risque, STAGE_RISK['idea'] = 1.0).
        $project = new Project('Test', $country, $sector, '100000.00', 'x', 'x');
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        $service = static::getContainer()->get(InvestmentRiskScoreService::class);
        $score = $service->scoreForProject($project);

        // country_risk (1-0.5=0.5) + sector_risk (1-0.5=0.5) + project_stage_risk
        // (1-1.0=0.0), poids egaux 1/3 chacun -> (0.5+0.5+0)/3 = 0.3333... -> 33.33, grade D.
        self::assertSame(33.33, $score->getValue());
        self::assertSame('D', $score->getGrade());
        self::assertCount(3, $score->getBreakdown());
    }

    public function testScoreValueIsAlwaysWithinZeroToOneHundred(): void
    {
        $country = new Country('Ghana', 'GHA', "Afrique de l'Ouest");
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        $score = static::getContainer()->get(InvestmentRiskScoreService::class)->scoreForCountry($country);

        self::assertGreaterThanOrEqual(0.0, $score->getValue());
        self::assertLessThanOrEqual(100.0, $score->getValue());
    }
}
