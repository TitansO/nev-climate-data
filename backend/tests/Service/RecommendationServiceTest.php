<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Country;
use App\Service\RecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C3.7 : RecommendationService. Un pays sans aucune donnée réelle (ni
 * financement, ni NdcTarget, ni émission) mais avec population +
 * vulnérabilité renseignées retombe entièrement sur les valeurs neutres
 * documentées de chaque score sous-jacent (EquityScoreService = 50.0,
 * InvestmentRiskScoreService = 50.0 pour "aucune donnée" -
 * App\Service\Scoring\CountryRiskCalculator::calculate()), et
 * NdcAlignmentScoreService/le gap C3.1 restent absents (aucun NdcTarget) -
 * un scénario entièrement déterministe sans avoir à répliquer le détail de
 * ces deux scores composites.
 */
final class RecommendationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        static::getContainer()->get('cache.analytics')->clear();
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

    public function testGetRecommendationsExcludesCountriesOutsideTheEquityPool(): void
    {
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est"); // no population/vulnerabilityIndex
        $this->entityManager->persist($kenya);
        $this->entityManager->flush();

        $service = static::getContainer()->get(RecommendationService::class);

        self::assertSame([], $service->getRecommendations());
    }

    public function testGetRecommendationsDegradesGracefullyToOnlyTheAvailableSignals(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $senegal->setPopulation(1000);
        $senegal->setVulnerabilityIndex(0.5);
        $this->entityManager->persist($senegal);
        $this->entityManager->flush();

        $service = static::getContainer()->get(RecommendationService::class);
        $rows = $service->getRecommendations();

        self::assertCount(1, $rows);
        self::assertSame('Senegal', $rows[0]['country']);
        self::assertSame('SEN', $rows[0]['countryIsoCode']);
        // No NdcTarget at all -> no C3.1 gap row and NdcAlignmentScoreService
        // returns null -> both signals absent, never fabricated.
        self::assertNull($rows[0]['signals']['needGap']);
        self::assertNull($rows[0]['signals']['ndcGap']);
        // No funding/emission data -> EquityScoreService's 3 indicators all
        // fall back to their documented neutral value (0.5) -> equity score
        // 50.0 -> equityGap = 1 - 50/100 = 0.5.
        self::assertSame(0.5, $rows[0]['signals']['equityGap']);
        // No funding data at all -> CountryRiskCalculator's "no_data" neutral
        // (0.5) -> investment risk score 50.0 -> investmentSafety = 0.5.
        self::assertSame(0.5, $rows[0]['signals']['investmentSafety']);
        // priorityScore = average of the only 2 available signals (0.5, 0.5).
        self::assertSame(0.5, $rows[0]['priorityScore']);
        self::assertStringContainsString('Senegal', $rows[0]['justification']);
    }
}
