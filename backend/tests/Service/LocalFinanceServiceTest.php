<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Country;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Sector;
use App\Entity\Source;
use App\Service\LocalFinanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * C3.2 : LocalFinanceService::getLocalFinance(). Proxy = instrument grant
 * ET montant < 1 000 000 USD. Dataset : Senegal reçoit un petit don (200k,
 * compte comme "local") et un gros prêt concessionnel (2M, ne compte pas).
 */
final class LocalFinanceServiceTest extends KernelTestCase
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

    public function testGetLocalFinanceRejectsAnUnknownCountryIsoCode(): void
    {
        $service = static::getContainer()->get(LocalFinanceService::class);

        $this->expectException(BadRequestHttpException::class);
        $service->getLocalFinance('ZZZ');
    }

    public function testGetLocalFinanceEstimatesTheSmallGrantShare(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $sector = new Sector('Renewable Energy');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $smallGrant = new Funding($senegal, $sector, 2024, '200000.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated);
        $smallGrant->setInstrument(FundingInstrument::Grant);
        $this->entityManager->persist($smallGrant);

        $bigLoan = new Funding($senegal, $sector, 2024, '2000000.00', FundingType::Private, $source, new \DateTimeImmutable('2024-02-01'), ValidationStatus::Validated);
        $bigLoan->setInstrument(FundingInstrument::LoanConcessional);
        $this->entityManager->persist($bigLoan);

        $this->entityManager->flush();

        $service = static::getContainer()->get(LocalFinanceService::class);
        $result = $service->getLocalFinance('SEN');

        self::assertCount(1, $result['data']);
        self::assertSame('Senegal', $result['data'][0]['country']);
        self::assertSame(2200000.0, $result['data'][0]['totalUsd']);
        self::assertSame(200000.0, $result['data'][0]['estimatedLocalUsd']);
        self::assertEqualsWithDelta(9.09, $result['data'][0]['localSharePercent'], 0.01);
        self::assertNotSame('', $result['methodology']);
    }

    public function testGetLocalFinanceExcludesALargeGrantFromTheLocalEstimate(): void
    {
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $sector = new Sector('Agriculture');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($kenya);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $bigGrant = new Funding($kenya, $sector, 2024, '5000000.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated);
        $bigGrant->setInstrument(FundingInstrument::Grant);
        $this->entityManager->persist($bigGrant);
        $this->entityManager->flush();

        $service = static::getContainer()->get(LocalFinanceService::class);
        $result = $service->getLocalFinance('KEN');

        self::assertSame(0.0, $result['data'][0]['estimatedLocalUsd']);
        self::assertSame(0.0, $result['data'][0]['localSharePercent']);
    }
}
