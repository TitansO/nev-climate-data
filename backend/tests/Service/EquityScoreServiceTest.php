<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Country;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\NdcTarget;
use App\Entity\Sector;
use App\Entity\Source;
use App\Service\EquityScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C3.3 : EquityScoreService. Un seul pays dans le "pool équité" par test ->
 * le ratio per_capita_vs_vulnerability du pays EST la moyenne du pool
 * (1 élément), donc compute() = 1.0 exactement, déterministe sans avoir à
 * répliquer la moyenne à la main. Aucune donnée Emission persistée -> le
 * 3e indicateur (financement/tCO2) retombe sur sa valeur neutre documentée
 * (0.5), également déterministe.
 */
final class EquityScoreServiceTest extends KernelTestCase
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

    public function testScoreForReturnsNullWithoutPopulationOrVulnerabilityIndex(): void
    {
        $country = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        $service = static::getContainer()->get(EquityScoreService::class);

        self::assertNull($service->scoreFor($country));
    }

    public function testScoreForCombinesTheThreeIndicators(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $senegal->setPopulation(1000);
        $senegal->setVulnerabilityIndex(0.5);
        $sector = new Sector('Renewable Energy');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $target = new NdcTarget($senegal, 2030);
        $target->setDeclaredNeedUsd('1000.00');
        $this->entityManager->persist($target);

        $this->entityManager->persist(new Funding($senegal, $sector, 2024, '500.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $service = static::getContainer()->get(EquityScoreService::class);
        $score = $service->scoreFor($senegal);

        self::assertNotNull($score);
        // 0.4*1.0 (per-capita, single-country pool = its own average) +
        // 0.3*0.5 (received 500 / need 1000) + 0.3*0.5 (no emission data, neutral) = 0.7 -> 70.0, grade B.
        self::assertSame(70.0, $score->getValue());
        self::assertSame('B', $score->getGrade());
        self::assertCount(3, $score->getBreakdown());
    }

    public function testGetRankingOnlyIncludesCountriesWithFullEquityData(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $senegal->setPopulation(1000);
        $senegal->setVulnerabilityIndex(0.5);
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est"); // no population/vulnerability
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($kenya);
        $this->entityManager->flush();

        $service = static::getContainer()->get(EquityScoreService::class);
        $ranking = $service->getRanking();

        self::assertCount(1, $ranking['ranking']);
        self::assertSame('SEN', $ranking['ranking'][0]['countryIsoCode']);
        self::assertArrayHasKey('giniIndex', $ranking);
    }
}
