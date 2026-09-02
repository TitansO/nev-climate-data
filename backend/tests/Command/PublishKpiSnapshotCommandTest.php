<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PublishKpiSnapshotCommand;
use App\Entity\Country;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Sector;
use App\Entity\Source;
use App\Service\AnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use Predis\Client as PredisClient;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * A3.5: closes a real coverage gap - the A3.1 KPI publisher had no test at
 * all. AnalyticsService is `final` with no interface (can't be mocked), so
 * this uses the real service resolved from the container - same "test the
 * real behavior" approach AnalyticsControllerTest already takes for the
 * same reason (see its own docblock) - with only HubInterface mocked
 * (a real interface, and the one genuinely external dependency: this test
 * has no business actually reaching a Mercure hub).
 */
final class PublishKpiSnapshotCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $this->redisClient()->flushdb();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $sector = new Sector('Renewable Energy');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);

        foreach ([$senegal, $kenya, $sector, $source] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist(new Funding($senegal, $sector, 2025, '300000.00', FundingType::Public, $source, new \DateTimeImmutable('2025-01-01'), ValidationStatus::Demo));
        $this->entityManager->persist(new Funding($kenya, $sector, 2025, '100000.00', FundingType::Private, $source, new \DateTimeImmutable('2025-01-01'), ValidationStatus::Demo));
        $this->entityManager->flush();
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

    private function redisClient(): PredisClient
    {
        $dsn = getenv('REDIS_DSN') ?: 'redis://redis:6379';
        $parts = parse_url($dsn);

        return new PredisClient(['host' => $parts['host'] ?? 'redis', 'port' => $parts['port'] ?? 6379]);
    }

    public function testOncePublishesASnapshotMatchingTheRealAnalyticsAggregates(): void
    {
        /** @var Update|null $published */
        $published = null;

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::isInstanceOf(Update::class))
            ->willReturnCallback(function (Update $update) use (&$published): string {
                $published = $update;

                return 'mock-id';
            });

        $command = new PublishKpiSnapshotCommand(static::getContainer()->get(AnalyticsService::class), $hub);
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        $tester = new CommandTester($application->find('app:mercure:publish-kpis'));
        $exitCode = $tester->execute(['--once' => true]);

        self::assertSame(0, $exitCode);
        self::assertNotNull($published, 'HubInterface::publish() must have been called');
        self::assertSame([PublishKpiSnapshotCommand::TOPIC], $published->getTopics());
        self::assertFalse($published->isPrivate(), 'the KPI topic is public data - see the command\'s own docblock');

        $payload = json_decode($published->getData(), true, flags: \JSON_THROW_ON_ERROR);
        // json_decode gives back an int here (400000, not 400000.0 - PHP's
        // json_encode drops the trailing .0 for a whole-number float, and
        // json_decode has no way to tell it was ever a float) - assertEquals
        // rather than assertSame is the correct comparison for a JSON-
        // round-tripped numeric amount, not a type mistake to "fix" away.
        self::assertEquals(400000.0, $payload['fundingTotalUsd']);
        self::assertSame(2, $payload['countriesCovered']);
        self::assertArrayHasKey('publishedAt', $payload);
    }

    public function testAPublishFailureIsLoggedNotThrown(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('hub unreachable'));

        $command = new PublishKpiSnapshotCommand(static::getContainer()->get(AnalyticsService::class), $hub);
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        $tester = new CommandTester($application->find('app:mercure:publish-kpis'));
        $exitCode = $tester->execute(['--once' => true]);

        // Command::SUCCESS even though the publish itself failed - a
        // long-running loop must survive a transient hub outage (see the
        // command's own docblock) rather than crash the whole process.
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('hub unreachable', $tester->getDisplay());
    }
}
