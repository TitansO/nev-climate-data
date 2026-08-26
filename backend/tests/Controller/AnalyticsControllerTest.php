<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Sector;
use App\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;
use Predis\Client as PredisClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * GET /api/analytics/* (A2.5). Dataset seeded by seedDataset():
 *   - 2024: 3x Renewable Energy/public ($100 each = $300), 2x Agriculture/private ($50 each = $100)
 *   - 2025: 1x Renewable Energy/multilateral ($400)
 * Chosen so financing-trends has two distinct years each with a distinct
 * funding-type mix, and sector-distribution has two sectors with an
 * unambiguous ranking (Renewable Energy $700 > Agriculture $100).
 *
 * Cache assertions talk to Redis directly (via Predis, same client the app
 * itself uses - see composer.json) rather than mocking anything: this is
 * the "test the real behavior" the task requires, not a simulation.
 */
final class AnalyticsControllerTest extends WebTestCase
{
    private const CACHE_KEY_FINANCING_TRENDS = 'analytics_financing_trends';
    private const CACHE_KEY_SECTOR_DISTRIBUTION = 'analytics_sector_distribution';
    private const CACHE_KEY_CO2_REDUCTION = 'analytics_co2_reduction';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->close();
        }
        $this->redisClient()->flushdb();
        parent::tearDown();
    }

    private function redisClient(): PredisClient
    {
        $dsn = getenv('REDIS_DSN') ?: 'redis://redis:6379';
        \assert(is_string($dsn));
        $parts = parse_url($dsn);

        return new PredisClient(['host' => $parts['host'] ?? 'redis', 'port' => $parts['port'] ?? 6379]);
    }

    /**
     * Symfony's Redis cache adapter stores each item under
     * "<namespace-hash>:<key>", not the bare key passed to
     * CacheInterface::get() - confirmed live (redis-cli KEYS) rather than
     * assumed. Matching by suffix is the only way to find an item without
     * hardcoding that internal, adapter-generated namespace.
     */
    private function findCacheKey(PredisClient $redis, string $keySuffix): ?string
    {
        $matches = $redis->keys('*'.$keySuffix);

        return $matches[0] ?? null;
    }

    private function seedDataset(KernelBrowser $client): void
    {
        $client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);

        foreach ([$senegal, $renewableEnergy, $agriculture, $source] as $entity) {
            $this->entityManager->persist($entity);
        }

        for ($i = 0; $i < 3; ++$i) {
            $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2024, '100.00', FundingType::Public, $source, new \DateTimeImmutable('2024-03-15'), ValidationStatus::Demo));
        }
        for ($i = 0; $i < 2; ++$i) {
            $this->entityManager->persist(new Funding($senegal, $agriculture, 2024, '50.00', FundingType::Private, $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo));
        }
        $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2025, '400.00', FundingType::Multilateral, $source, new \DateTimeImmutable('2025-01-10'), ValidationStatus::Demo));

        $this->entityManager->flush();

        // The service's own cache pool, flushed so each test starts on a
        // guaranteed miss regardless of what a previous test left behind.
        $container->get('cache.analytics')->clear();
    }

    public function testFinancingTrendsIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends');

        self::assertResponseIsSuccessful();
    }

    public function testFinancingTrendsAggregatesByYearAndType(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends');

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(2, $data);

        self::assertSame(2024, $data[0]['period']);
        // assertEquals, not assertSame: json_encode() drops the trailing
        // ".0" from a whole-number float (e.g. 300.0 -> "300"), so a
        // round-tripped whole amount decodes back as a PHP int. That's a
        // JSON-wire-format detail, not a bug - a JS consumer's `number`
        // doesn't distinguish int/float either.
        self::assertEquals(300.0, $data[0]['public']);
        self::assertEquals(100.0, $data[0]['private']);
        self::assertEquals(0.0, $data[0]['multilateral']);
        self::assertEquals(400.0, $data[0]['total']);

        self::assertSame(2025, $data[1]['period']);
        self::assertEquals(0.0, $data[1]['public']);
        self::assertEquals(400.0, $data[1]['multilateral']);
        self::assertEquals(400.0, $data[1]['total']);
    }

    public function testFinancingTrendsOrderIsChronological(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends');

        $periods = array_column(json_decode($client->getResponse()->getContent(), true)['data'], 'period');
        self::assertSame([2024, 2025], $periods);
    }

    public function testSectorDistributionAggregatesAndComputesPercentage(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/sector-distribution');

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(2, $data);

        // Renewable Energy: 300 (2024) + 400 (2025) = 700, largest -> first.
        self::assertSame('Renewable Energy', $data[0]['sector']);
        self::assertEquals(700.0, $data[0]['amount']);
        self::assertEquals(87.5, $data[0]['percentage']); // 700 / 800 * 100

        self::assertSame('Agriculture', $data[1]['sector']);
        self::assertEquals(100.0, $data[1]['amount']);
        self::assertEquals(12.5, $data[1]['percentage']);
    }

    public function testCo2ReductionExplicitlyReportsUnavailableRatherThanFabricatingAValue(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/co2-reduction');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['available']);
        self::assertNull($data['data']);
        self::assertArrayHasKey('reason', $data);
        self::assertNotSame('', $data['reason']);
    }

    public function testEmptyDatasetReturnsEmptyDataArraysNotAnError(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $container->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/financing-trends');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);

        $client->request('GET', '/api/analytics/sector-distribution');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
    }

    public function testAnalyticsEndpointsAreDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        foreach (['/api/analytics/financing-trends', '/api/analytics/sector-distribution', '/api/analytics/co2-reduction'] as $path) {
            self::assertArrayHasKey($path, $spec['paths']);
            self::assertArrayHasKey('get', $spec['paths'][$path]);
        }
    }

    public function testFirstRequestPopulatesTheRedisCacheWithA900SecondTtl(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $redis = $this->redisClient();

        self::assertNull($this->findCacheKey($redis, self::CACHE_KEY_FINANCING_TRENDS), 'key must not exist before the first request');

        $client->request('GET', '/api/analytics/financing-trends');
        self::assertResponseIsSuccessful();

        $key = $this->findCacheKey($redis, self::CACHE_KEY_FINANCING_TRENDS);
        self::assertNotNull($key, 'first request must populate Redis');
        $ttl = $redis->ttl($key);
        self::assertGreaterThan(890, $ttl, 'TTL must be close to 900s (A2.5 requirement)');
        self::assertLessThanOrEqual(900, $ttl);
    }

    public function testEachAggregateUsesItsOwnCacheKey(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $redis = $this->redisClient();

        $client->request('GET', '/api/analytics/financing-trends');
        $client->request('GET', '/api/analytics/sector-distribution');
        $client->request('GET', '/api/analytics/co2-reduction');

        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_FINANCING_TRENDS));
        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_SECTOR_DISTRIBUTION));
        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_CO2_REDUCTION));
    }

    /**
     * The real proof a cache is in effect: change the underlying data
     * without going through AnalyticsService, then confirm a second HTTP
     * call still returns the value computed (and cached) before the
     * change - i.e. it was actually served from Redis, not recalculated.
     */
    public function testSecondRequestIsServedFromCacheEvenAfterTheUnderlyingDataChanges(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/sector-distribution');
        $firstResponse = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(700.0, $firstResponse[0]['amount']);

        // Mutate the dataset directly, bypassing AnalyticsService entirely.
        $senegal = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);
        $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2024, '999999.00', FundingType::Public, $source, new \DateTimeImmutable('2024-03-15'), ValidationStatus::Demo));
        $this->entityManager->flush();

        $client->request('GET', '/api/analytics/sector-distribution');
        $secondResponse = json_decode($client->getResponse()->getContent(), true)['data'];

        self::assertEquals(700.0, $secondResponse[0]['amount'], 'must still be the cached pre-change value, not a recomputed one');
    }
}
