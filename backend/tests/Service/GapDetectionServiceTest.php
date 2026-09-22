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
use App\Service\GapDetectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * C3.1 : GapDetectionService::getGaps(). Dataset : Nigeria a une cible CDN
 * économie entière à 1000 (reçu 100 -> ratio 0.1, "critical"), Kenya a une
 * cible à 1000 sans montant déclaré ignorée par NdcTargetRepository::
 * findAllWithDeclaredNeed() elle-même (pas testée ici en doublon).
 */
final class GapDetectionServiceTest extends KernelTestCase
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

    public function testGetGapsRejectsAnUnknownByValue(): void
    {
        $service = static::getContainer()->get(GapDetectionService::class);

        $this->expectException(BadRequestHttpException::class);
        $service->getGaps('invalid');
    }

    public function testGetGapsByCountryComputesCoverageRatioAndSeverity(): void
    {
        $nigeria = new Country('Nigeria', 'NGA', 'Afrique de l\'Ouest');
        $sector = new Sector('Renewable Energy');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($nigeria);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $target = new NdcTarget($nigeria, 2030);
        $target->setDeclaredNeedUsd('1000.00');
        $this->entityManager->persist($target);

        $this->entityManager->persist(new Funding($nigeria, $sector, 2024, '100.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $service = static::getContainer()->get(GapDetectionService::class);
        $rows = $service->getGaps('country');

        self::assertCount(1, $rows);
        self::assertSame('Nigeria', $rows[0]['country']);
        self::assertSame('NGA', $rows[0]['countryIsoCode']);
        self::assertSame(1000.0, $rows[0]['declaredNeedUsd']);
        self::assertSame(100.0, $rows[0]['receivedUsd']);
        self::assertSame(900.0, $rows[0]['gapUsd']);
        self::assertSame(0.1, $rows[0]['coverageRatio']);
        self::assertSame('critical', $rows[0]['severity']);
    }

    public function testGetGapsByCountryExcludesTargetsWithoutADeclaredNeed(): void
    {
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $this->entityManager->persist($kenya);
        $target = new NdcTarget($kenya, 2030);
        // declaredNeedUsd left null on purpose.
        $this->entityManager->persist($target);
        $this->entityManager->flush();

        $service = static::getContainer()->get(GapDetectionService::class);
        $rows = $service->getGaps('country');

        self::assertSame([], $rows);
    }

    public function testGetGapsByCountryExcludesSectorSpecificTargets(): void
    {
        $ghana = new Country('Ghana', 'GHA', "Afrique de l'Ouest");
        $sector = new Sector('Agriculture');
        $this->entityManager->persist($ghana);
        $this->entityManager->persist($sector);
        $target = new NdcTarget($ghana, 2030);
        $target->setDeclaredNeedUsd('500.00');
        $target->setSector($sector);
        $this->entityManager->persist($target);
        $this->entityManager->flush();

        $service = static::getContainer()->get(GapDetectionService::class);

        // by=country only compares economy-wide targets (sector === null).
        self::assertSame([], $service->getGaps('country'));
        // by=country-sector is the mode that covers a sector-specific target.
        $bySectorRows = $service->getGaps('country-sector');
        self::assertCount(1, $bySectorRows);
        self::assertSame('Ghana', $bySectorRows[0]['country']);
        self::assertSame('Agriculture', $bySectorRows[0]['sector']);
    }

    public function testGetGapsAboveTheModerateThresholdIsNotFlaggedCritical(): void
    {
        $morocco = new Country('Morocco', 'MAR', 'Afrique du Nord');
        $sector = new Sector('Renewable Energy');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($morocco);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $target = new NdcTarget($morocco, 2030);
        $target->setDeclaredNeedUsd('1000.00');
        $this->entityManager->persist($target);
        // 800/1000 = 0.8 coverage, above the 0.75 "moderate" ceiling -> "low".
        $this->entityManager->persist(new Funding($morocco, $sector, 2024, '800.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $service = static::getContainer()->get(GapDetectionService::class);
        $rows = $service->getGaps('country');

        self::assertSame('low', $rows[0]['severity']);
    }
}
