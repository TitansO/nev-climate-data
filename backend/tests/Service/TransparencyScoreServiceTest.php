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
use App\Service\TransparencyScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C3.6 : TransparencyScoreService. Granularity et DescriptivePresence
 * (App\Service\Scoring\Transparency\{Granularity,DescriptivePresence}Indicator)
 * sont toujours neutres (0.5) par construction (données non disponibles
 * dans ce schéma) - déterministe sans configuration particulière.
 */
final class TransparencyScoreServiceTest extends KernelTestCase
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

    public function testScoreForNeverReturnsNullEvenWithoutAnyFunding(): void
    {
        $source = new Source('Local Registry', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);
        $this->entityManager->flush();

        $service = static::getContainer()->get(TransparencyScoreService::class);
        $score = $service->scoreFor($source);

        self::assertCount(5, $score->getBreakdown());
        // completeness (0.5) + iati (0.0) + freshness (0.5) + granularity (0.5) + descriptive (0.5),
        // weights 0.3/0.2/0.2/0.2/0.1 -> 0.3*0.5+0.2*0+0.2*0.5+0.2*0.5+0.1*0.5 = 0.15+0+0.1+0.1+0.05 = 0.4 -> 40.0
        self::assertSame(40.0, $score->getValue());
    }

    public function testScoreForACompleteFreshIatiSourceScoresHigh(): void
    {
        $country = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $sector = new Sector('Renewable Energy');
        $source = new Source('IATI Datastore - GCF', SourceType::OfficialApi, SourceReliability::High);
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $funding = new Funding($country, $sector, (int) date('Y'), '300.00', FundingType::Public, $source, new \DateTimeImmutable(), ValidationStatus::Validated);
        $funding->setInstrument(FundingInstrument::Grant);
        $funding->setOriginalAmount('300.00');
        $this->entityManager->persist($funding);
        $this->entityManager->flush();

        $service = static::getContainer()->get(TransparencyScoreService::class);
        $score = $service->scoreFor($source);

        // completeness (1.0) + iati (1.0) + freshness (~1.0, collected today) + granularity (0.5) + descriptive (0.5)
        // -> 0.3+0.2+0.2+0.1+0.05 = 0.85 -> 85.0, grade A.
        self::assertSame(85.0, $score->getValue());
        self::assertSame('A', $score->getGrade());
    }
}
