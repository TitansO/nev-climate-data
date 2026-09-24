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
 * F3 : le moteur générique de scoring, testé avec des indicateurs bouchons.
 * Utilise le vrai ScoreRepository (base réelle) plutôt qu'un mock - même
 * approche que PublishKpiSnapshotCommandTest pour la même raison :
 * ScoreService n'a pas d'interface, et c'est le comportement réel de
 * persistance (upsert en place) qui compte ici.
 *
 * Audit pre-production 2026-09-24 : ce fichier testait l'architecture
 * d'origine (`new ScoreService($indicators, $repo)` + `recompute()` qui
 * filtrait lui-même les indicateurs par scoreType/subjectType) - retirée
 * au profit de l'architecture actuelle (liste d'indicateurs déjà filtrée
 * passée explicitement à `compute()`, voir le docblock de ScoreService)
 * lors de la construction de C2.4/C2.6. Plus jamais réexécuté depuis -
 * une classe anonyme avec une signature `compute()` incompatible avec
 * l'interface actuelle faisait planter tout le process PHPUnit (erreur
 * fatale, pas un simple échec d'assertion) dès que ce fichier était
 * inclus dans une exécution complète. Réécrit pour coller à l'API
 * réelle ; les 2 tests qui reposaient sur le filtrage auto-disparu
 * (indicateur "sans valeur" -> null, indicateur ignoré car scoreType/
 * subjectType différent) sont retirés - ce comportement n'existe plus,
 * c'est désormais l'appelant qui construit la liste exacte à passer.
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

    private function makeIndicator(float $weight, float $value, string $code = 'stub'): ScoreIndicatorInterface
    {
        return new class($code, $weight, $value) implements ScoreIndicatorInterface {
            public function __construct(
                private readonly string $code,
                private readonly float $weight,
                private readonly float $value,
            ) {
            }

            public function code(): string
            {
                return $this->code;
            }

            public function weight(): float
            {
                return $this->weight;
            }

            public function compute(mixed $subject): float
            {
                return $this->value;
            }

            public function explain(mixed $subject): string
            {
                return \sprintf('stub indicator %s', $this->code);
            }
        };
    }

    public function testComputeCombinesWeightedIndicatorsIntoAScoreWithBreakdown(): void
    {
        $country = $this->persistCountry();
        $scoreRepository = static::getContainer()->get(ScoreRepository::class);
        $service = new ScoreService($scoreRepository);

        $score = $service->compute(
            ScoreSubjectType::Country,
            $country->getId(),
            'test_score',
            $country,
            [
                $this->makeIndicator(0.6, 0.8, 'indicator_a'),
                $this->makeIndicator(0.4, 0.5, 'indicator_b'),
            ],
        );

        // (0.8*0.6 + 0.5*0.4) / (0.6+0.4) = 0.68 -> 68.0
        self::assertSame(68.0, $score->getValue());
        self::assertSame('B', $score->getGrade());
        self::assertCount(2, $score->getBreakdown());
        self::assertSame('indicator_a', $score->getBreakdown()[0]['code']);
    }

    public function testComputeUpsertsInPlaceRatherThanCreatingANewRow(): void
    {
        $country = $this->persistCountry();
        $scoreRepository = static::getContainer()->get(ScoreRepository::class);
        $service = new ScoreService($scoreRepository);

        $first = $service->compute(ScoreSubjectType::Country, $country->getId(), 'test_score', $country, [$this->makeIndicator(1.0, 0.5)]);
        $second = $service->compute(ScoreSubjectType::Country, $country->getId(), 'test_score', $country, [$this->makeIndicator(1.0, 0.9)]);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(90.0, $second->getValue());
    }
}
