<?php

declare(strict_types=1);

namespace App\Tests\Service\Scoring;

use App\Entity\Country;
use App\Entity\Enum\ScoreSubjectType;
use App\Repository\ScoreRepository;
use App\Service\Scoring\ScoreIndicatorInterface;
use App\Service\Scoring\ScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * F3 : le moteur générique de scoring, testé avec des indicateurs bouchons
 * (aucun indicateur concret n'existe encore - ceux-ci arrivent avec C2.4,
 * C2.6, etc.). Utilise le vrai ScoreRepository (base réelle) plutôt qu'un
 * mock - même approche que PublishKpiSnapshotCommandTest pour la même
 * raison : ScoreService n'a pas d'interface, et c'est le comportement
 * réel de persistance (upsert en place) qui compte ici.
 */
final class ScoreServiceTest extends KernelTestCase
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

    private function persistCountry(): Country
    {
        $country = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        return $country;
    }

    private function makeIndicator(string $scoreType, ScoreSubjectType $subjectType, float $weight, ?float $value, string $code = 'stub'): ScoreIndicatorInterface
    {
        return new class($code, $scoreType, $subjectType, $weight, $value) implements ScoreIndicatorInterface {
            public function __construct(
                private readonly string $code,
                private readonly string $scoreType,
                private readonly ScoreSubjectType $subjectType,
                private readonly float $weight,
                private readonly ?float $value,
            ) {
            }

            public function code(): string
            {
                return $this->code;
            }

            public function scoreType(): string
            {
                return $this->scoreType;
            }

            public function subjectType(): ScoreSubjectType
            {
                return $this->subjectType;
            }

            public function weight(): float
            {
                return $this->weight;
            }

            public function compute(int $subjectId): ?float
            {
                return $this->value;
            }

            public function explain(int $subjectId): string
            {
                return \sprintf('stub indicator %s for subject %d', $this->code, $subjectId);
            }
        };
    }

    public function testRecomputeCombinesWeightedIndicatorsIntoAScoreWithBreakdown(): void
    {
        $country = $this->persistCountry();
        $scoreRepository = static::getContainer()->get(ScoreRepository::class);
        $service = new ScoreService(
            [
                $this->makeIndicator('test_score', ScoreSubjectType::Country, 0.6, 0.8, 'indicator_a'),
                $this->makeIndicator('test_score', ScoreSubjectType::Country, 0.4, 0.5, 'indicator_b'),
            ],
            $scoreRepository,
        );

        $score = $service->recompute('test_score', ScoreSubjectType::Country, $country->getId());

        self::assertNotNull($score);
        // (0.8*0.6 + 0.5*0.4) / (0.6+0.4) = 0.68 -> 68.0
        self::assertSame(68.0, $score->getValue());
        self::assertSame('B', $score->getGrade());
        self::assertCount(2, $score->getBreakdown());
        self::assertSame('indicator_a', $score->getBreakdown()[0]['code']);
    }

    public function testRecomputeReturnsNullWhenNoIndicatorCanCompute(): void
    {
        $country = $this->persistCountry();
        $scoreRepository = static::getContainer()->get(ScoreRepository::class);
        $service = new ScoreService(
            [$this->makeIndicator('test_score', ScoreSubjectType::Country, 1.0, null)],
            $scoreRepository,
        );

        $score = $service->recompute('test_score', ScoreSubjectType::Country, $country->getId());

        self::assertNull($score);
    }

    public function testRecomputeIgnoresIndicatorsForADifferentScoreTypeOrSubjectType(): void
    {
        $country = $this->persistCountry();
        $scoreRepository = static::getContainer()->get(ScoreRepository::class);
        $service = new ScoreService(
            [
                $this->makeIndicator('other_score', ScoreSubjectType::Country, 1.0, 0.9),
                $this->makeIndicator('test_score', ScoreSubjectType::Source, 1.0, 0.9),
                $this->makeIndicator('test_score', ScoreSubjectType::Country, 1.0, 0.5),
            ],
            $scoreRepository,
        );

        $score = $service->recompute('test_score', ScoreSubjectType::Country, $country->getId());

        self::assertNotNull($score);
        self::assertCount(1, $score->getBreakdown());
        self::assertSame(50.0, $score->getValue());
    }

    public function testRecomputeUpsertsInPlaceRatherThanCreatingANewRow(): void
    {
        $country = $this->persistCountry();
        $scoreRepository = static::getContainer()->get(ScoreRepository::class);

        $first = (new ScoreService([$this->makeIndicator('test_score', ScoreSubjectType::Country, 1.0, 0.5)], $scoreRepository))
            ->recompute('test_score', ScoreSubjectType::Country, $country->getId());
        $second = (new ScoreService([$this->makeIndicator('test_score', ScoreSubjectType::Country, 1.0, 0.9)], $scoreRepository))
            ->recompute('test_score', ScoreSubjectType::Country, $country->getId());

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->getId(), $second->getId());
        self::assertSame(90.0, $second->getValue());
    }
}
