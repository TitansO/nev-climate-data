<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Country;
use App\Entity\Emission;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Source;
use App\Repository\EmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmissionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EmissionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(EmissionRepository::class);
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

    public function testDistinctYearsWithCurrentDataIgnoresHistorizedAndOrdersDescending(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($source);

        $y2023 = new Emission($senegal, 2023, '10.500', $source, new \DateTimeImmutable('2023-06-01'), ValidationStatus::Validated);
        $y2024 = new Emission($senegal, 2024, '11.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Validated);
        $historizedY2025 = new Emission($senegal, 2025, '999.000', $source, new \DateTimeImmutable('2025-06-01'), ValidationStatus::Validated);
        $historizedY2025->setIsCurrent(false);
        foreach ([$y2023, $y2024, $historizedY2025] as $emission) {
            $this->entityManager->persist($emission);
        }
        $this->entityManager->flush();

        // 2025 has no *current* row - must not appear, even though a row for that year exists.
        self::assertSame([2024, 2023], $this->repository->distinctYearsWithCurrentData());
    }

    public function testTotalsByYearSumsPerCountryAndExcludesHistorizedRows(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($kenya);
        $this->entityManager->persist($source);

        $senegal2024 = new Emission($senegal, 2024, '11.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Validated);
        $kenya2024 = new Emission($kenya, 2024, '5.250', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Validated);
        $historizedSenegal2024 = new Emission($senegal, 2024, '999.000', $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated);
        $historizedSenegal2024->setIsCurrent(false);
        foreach ([$senegal2024, $kenya2024, $historizedSenegal2024] as $emission) {
            $this->entityManager->persist($emission);
        }
        $this->entityManager->flush();

        $totals = $this->repository->totalsByYear(2024);

        self::assertEqualsWithDelta(11.0, $totals[$senegal->getId()], 0.001);
        self::assertEqualsWithDelta(5.25, $totals[$kenya->getId()], 0.001);
    }

    public function testDemoRowsAreExcludedFromBothQueries(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($source);

        // 2024 has only a Demo row - B3.3: it must not appear as an available
        // year, and must not contribute to the 2023 totals either.
        $this->entityManager->persist(new Emission($senegal, 2023, '10.000', $source, new \DateTimeImmutable('2023-06-01'), ValidationStatus::Validated));
        $this->entityManager->persist(new Emission($senegal, 2022, '9.000', $source, new \DateTimeImmutable('2022-06-01'), ValidationStatus::Validated));
        $this->entityManager->persist(new Emission($senegal, 2024, '999.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo));
        $this->entityManager->flush();

        self::assertSame([2023, 2022], $this->repository->distinctYearsWithCurrentData());
        self::assertSame([], $this->repository->totalsByYear(2024));
    }
}
