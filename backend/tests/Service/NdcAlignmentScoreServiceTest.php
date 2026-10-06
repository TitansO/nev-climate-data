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
use App\Service\NdcAlignmentScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C2.6 : NdcAlignmentScoreService - premier test jamais écrit pour ce
 * score, malgré un endpoint public déjà en production (audit du
 * 2026-09-23). Ce score est le seul des 9 à combiner à la fois "aucun
 * test" ET une donnée sous-jacente (NdcTargetFixtures) explicitement
 * étiquetée "reconstitution de mémoire, à vérifier avant usage réel" -
 * raison pour laquelle l'audit l'a classé Majeur plutôt que Moyen.
 */
final class NdcAlignmentScoreServiceTest extends KernelTestCase
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

    public function testScoreForReturnsNullWithoutAnyNdcTarget(): void
    {
        $country = new Country('Uganda', 'UGA', "Afrique de l'Est");
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        $service = static::getContainer()->get(NdcAlignmentScoreService::class);

        self::assertNull($service->scoreFor($country));
    }

    public function testConditionalTargetWithNoRealFundingScoresLow(): void
    {
        $country = new Country('Ethiopia', 'ETH', "Afrique de l'Est");
        $sector = new Sector('Renewable Energy');
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);

        $target = new NdcTarget($country, 2030);
        $target->setConditional(true);
        // declaredNeedUsd et sector volontairement laisses null (cible
        // economie entiere, besoin non chiffre - cas frequent dans
        // NdcTargetFixtures, fiabilisee le 2026-10-06 : 4 des 10 pays
        // desormais couverts n'ont pas de besoin chiffre fiable).
        $this->entityManager->persist($target);
        $this->entityManager->flush();

        $service = static::getContainer()->get(NdcAlignmentScoreService::class);
        $score = $service->scoreFor($country);

        self::assertNotNull($score);
        // sector_coverage (0.0, aucun financement reel dans le seul secteur
        // existant) * 0.5 + received_vs_need_ratio (0.5, besoin non chiffre) * 0.3
        // + unconditional_financing (0.0, cible conditionnelle) * 0.2
        // = 0 + 0.15 + 0 = 0.15 -> 15.0, grade E.
        self::assertSame(15.0, $score->getValue());
        self::assertSame('E', $score->getGrade());
    }

    public function testUnconditionalTargetWithHalfNeedCoveredAndFundedSectorScoresHigh(): void
    {
        $country = new Country('Morocco', 'MAR', 'Afrique du Nord');
        $sector = new Sector('Renewable Energy');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $target = new NdcTarget($country, 2030);
        $target->setDeclaredNeedUsd('1000.00');
        $target->setConditional(false);
        $this->entityManager->persist($target);

        $this->entityManager->persist(new Funding($country, $sector, 2024, '500.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $service = static::getContainer()->get(NdcAlignmentScoreService::class);
        $score = $service->scoreFor($country);

        // sector_coverage (1.0, le seul secteur existant a du financement reel) * 0.5
        // + received_vs_need_ratio (500/1000 = 0.5) * 0.3
        // + unconditional_financing (1.0, cible inconditionnelle) * 0.2
        // = 0.5 + 0.15 + 0.2 = 0.85 -> 85.0, grade A.
        self::assertSame(85.0, $score->getValue());
        self::assertSame('A', $score->getGrade());
        self::assertCount(3, $score->getBreakdown());
    }
}
