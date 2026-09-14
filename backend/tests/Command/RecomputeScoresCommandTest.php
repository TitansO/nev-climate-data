<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RecomputeScoresCommand;
use App\Entity\Country;
use App\Entity\Enum\ScoreSubjectType;
use App\Repository\CountryRepository;
use App\Repository\FundingRepository;
use App\Repository\ProjectRepository;
use App\Repository\SourceRepository;
use App\Service\Scoring\ScoreIndicatorInterface;
use App\Service\Scoring\ScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * F3 : la commande de recalcul, testée dans son état réel actuel (aucun
 * indicateur concret enregistré - ceux-ci arrivent avec C2.4/C2.6/etc.)
 * et avec un indicateur bouchon injecté manuellement pour vérifier le
 * chemin "au moins un indicateur existe".
 */
final class RecomputeScoresCommandTest extends KernelTestCase
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

    public function testWithNoRegisteredIndicatorReportsNothingToDoAndSucceeds(): void
    {
        $command = new RecomputeScoresCommand(
            [],
            static::getContainer()->get(ScoreService::class),
            static::getContainer()->get(CountryRepository::class),
            static::getContainer()->get(SourceRepository::class),
            static::getContainer()->get(ProjectRepository::class),
            static::getContainer()->get(FundingRepository::class),
        );
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        $tester = new CommandTester($application->find('app:scores:recompute'));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('rien a recalculer', $tester->getDisplay());
    }

    public function testWithAStubIndicatorRecomputesEveryCountry(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($kenya);
        $this->entityManager->flush();

        $indicator = new class implements ScoreIndicatorInterface {
            public function code(): string
            {
                return 'stub';
            }

            public function scoreType(): string
            {
                return 'test_score';
            }

            public function subjectType(): ScoreSubjectType
            {
                return ScoreSubjectType::Country;
            }

            public function weight(): float
            {
                return 1.0;
            }

            public function compute(int $subjectId): ?float
            {
                return 0.5;
            }

            public function explain(int $subjectId): string
            {
                return 'stub';
            }
        };
        $scoreService = new ScoreService([$indicator], static::getContainer()->get(\App\Repository\ScoreRepository::class));

        $command = new RecomputeScoresCommand(
            [$indicator],
            $scoreService,
            static::getContainer()->get(CountryRepository::class),
            static::getContainer()->get(SourceRepository::class),
            static::getContainer()->get(ProjectRepository::class),
            static::getContainer()->get(FundingRepository::class),
        );
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        $tester = new CommandTester($application->find('app:scores:recompute'));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('test_score / country', $tester->getDisplay());
    }
}
