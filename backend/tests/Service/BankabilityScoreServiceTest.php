<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Country;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Project;
use App\Entity\Sector;
use App\Service\BankabilityScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C2.4 : BankabilityScoreService - premier test jamais écrit pour ce
 * score, malgré un endpoint public déjà en production (audit du
 * 2026-09-23, docs/superpowers/specs/2026-09-23-audit-production.md).
 * Scénarios sans aucune donnée Funding/NdcTarget pour rester
 * déterministes : chaque indicateur retombe sur sa valeur neutre
 * documentée (0.5) quand aucun comparable réel n'existe, exactement
 * comme CountryRiskCalculator/EquityScoreService ailleurs dans ce projet.
 */
final class BankabilityScoreServiceTest extends KernelTestCase
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

    public function testCompleteAndIdentifiedProjectWithNoComparablesScoresModerate(): void
    {
        $country = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $sector = new Sector('Renewable Energy');
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);

        $project = new Project(
            'Off-grid Solar Mini-Grids',
            $country,
            $sector,
            '2000000.00',
            str_repeat('Description detaillee du projet. ', 10), // >= 150 caracteres
            'Test Promoter'
        );
        $project->setInstrumentSought(FundingInstrument::Grant);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        $service = static::getContainer()->get(BankabilityScoreService::class);
        $score = $service->scoreFor($project);

        // completeness (1.0, instrument arrete + description longue) * 0.25
        // + promoter_identified (1.0, nom reel) * 0.1
        // + amount_sector_coherence (0.5, aucun comparable reel) * 0.2
        // + realistic_instrument (0.5, aucun financement reel pour ce pays) * 0.15
        // + country_risk_inverted (0.5, aucune donnee reelle pour ce pays) * 0.3
        // = 0.25 + 0.1 + 0.1 + 0.075 + 0.15 = 0.675 -> 67.5, grade B.
        self::assertSame(67.5, $score->getValue());
        self::assertSame('B', $score->getGrade());
        self::assertCount(5, $score->getBreakdown());
    }

    public function testIncompleteProjectWithPlaceholderPromoterScoresLow(): void
    {
        $country = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $sector = new Sector('Agriculture');
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);

        $project = new Project(
            'Vague Idea',
            $country,
            $sector,
            '500000.00',
            'Trop court.',
            'TBD'
        );
        // instrumentSought jamais renseigne (nullable par conception).
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        $service = static::getContainer()->get(BankabilityScoreService::class);
        $score = $service->scoreFor($project);

        // completeness (0.0, ni instrument ni description substantielle) * 0.25
        // + promoter_identified (0.0, "tbd" est un espace reserve connu) * 0.1
        // + amount_sector_coherence (0.5, aucun comparable) * 0.2
        // + realistic_instrument (0.5, instrument non arrete) * 0.15
        // + country_risk_inverted (0.5, aucune donnee) * 0.3
        // = 0 + 0 + 0.1 + 0.075 + 0.15 = 0.325 -> 32.5, grade D.
        self::assertSame(32.5, $score->getValue());
        self::assertSame('D', $score->getGrade());
    }

    public function testScoreValueIsAlwaysWithinZeroToOneHundred(): void
    {
        $country = new Country('Ghana', 'GHA', "Afrique de l'Ouest");
        $sector = new Sector('Forestry');
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);
        $project = new Project('Test', $country, $sector, '1.00', 'x', 'x');
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        $score = static::getContainer()->get(BankabilityScoreService::class)->scoreFor($project);

        self::assertGreaterThanOrEqual(0.0, $score->getValue());
        self::assertLessThanOrEqual(100.0, $score->getValue());
    }
}
