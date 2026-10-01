<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\Emission;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Predis\Client as PredisClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * GET /api/analytics/* (A2.5). Dataset seeded by seedDataset():
 *   - 2024: Renewable Energy/public $300, Agriculture/private $100
 *   - 2025: Renewable Energy/multilateral $400
 * Chosen so financing-trends has two distinct years each with a distinct
 * funding-type mix, and sector-distribution has two sectors with an
 * unambiguous ranking (Renewable Energy $700 > Agriculture $100).
 *
 * One Funding row per (country, sector, year, fundingType) group, each
 * already holding the summed amount, not one row per underlying
 * contribution - the B1.1 dedup constraint (unique per
 * source/country/sector/year/fundingType among current rows, see
 * Funding::class) means duplicates land as a single summed row, matching
 * how the real World Bank connector's upsert will behave in production.
 *
 * Cache assertions talk to Redis directly (via Predis, same client the app
 * itself uses - see composer.json) rather than mocking anything: this is
 * the "test the real behavior" the task requires, not a simulation.
 */
final class AnalyticsControllerTest extends WebTestCase
{
    private const CACHE_KEY_FINANCING_TRENDS = 'analytics_financing_trends_v2'; // bumped by C1.6, see AnalyticsService's own comment
    private const CACHE_KEY_SECTOR_DISTRIBUTION = 'analytics_sector_distribution';
    private const CACHE_KEY_COUNTRY_DISTRIBUTION = 'analytics_country_distribution';
    private const CACHE_KEY_CO2_REDUCTION = 'analytics_co2_reduction';
    private const CACHE_KEY_DATA_FRESHNESS = 'analytics_data_freshness';
    private const CACHE_KEY_INSTRUMENT_DISTRIBUTION = 'analytics_instrument_distribution';

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

    /**
     * Structuration des accès (2026-09-24) : POST /api/auth/login avec un
     * utilisateur déjà persisté et flush - jamais appelé avant que
     * l'appelant n'ait fait les deux, sinon "email ou mot de passe
     * incorrect".
     *
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function loginAs(KernelBrowser $client, User $user, string $plainPassword = 'correct-horse-battery-staple'): array
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => $plainPassword]));
        $token = json_decode($client->getResponse()->getContent(), true)['token'];

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function seedDataset(KernelBrowser $client): array
    {
        $client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        // Structuration des accès (2026-09-24) : /api/analytics/* est
        // désormais ROLE_USER (auparavant PUBLIC_ACCESS).
        $user = new User('Amina Diallo', 'analytics@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);

        foreach ([$senegal, $renewableEnergy, $agriculture, $source, $user] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2024, '300.00', FundingType::Public, $source, new \DateTimeImmutable('2024-03-15'), ValidationStatus::Validated));
        $this->entityManager->persist(new Funding($senegal, $agriculture, 2024, '100.00', FundingType::Private, $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Validated));
        $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2025, '400.00', FundingType::Multilateral, $source, new \DateTimeImmutable('2025-01-10'), ValidationStatus::Validated));

        $this->entityManager->flush();

        // The service's own cache pool, flushed so each test starts on a
        // guaranteed miss regardless of what a previous test left behind.
        $container->get('cache.analytics')->clear();

        return $this->loginAs($client, $user);
    }

    public function testFinancingTrendsRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testFinancingTrendsSucceedsForAnAuthenticatedUser(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);

        self::assertResponseIsSuccessful();
    }

    public function testFinancingTrendsAggregatesByYearAndType(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);

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
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);

        $periods = array_column(json_decode($client->getResponse()->getContent(), true)['data'], 'period');
        self::assertSame([2024, 2025], $periods);
    }

    /**
     * C1.6: groupBy=sector - seedDataset()'s 2024 row splits across two
     * sectors (Renewable Energy $300, Agriculture $100), 2025 has only
     * Renewable Energy ($400).
     */
    public function testFinancingTrendsGroupedBySectorAggregatesCorrectly(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends?groupBy=sector', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertSame(2024, $data[0]['period']);
        self::assertEquals(300.0, $data[0]['groups']['Renewable Energy']);
        self::assertEquals(100.0, $data[0]['groups']['Agriculture']);
        self::assertEquals(400.0, $data[0]['total']);

        self::assertSame(2025, $data[1]['period']);
        self::assertEquals(400.0, $data[1]['groups']['Renewable Energy']);
        self::assertArrayNotHasKey('Agriculture', $data[1]['groups']);
    }

    /**
     * C1.6: groupBy=fundingType must reproduce the exact same per-year
     * totals as the default (ungrouped) shape - only the JSON shape
     * differs (a generic `groups` object instead of hardcoded keys).
     */
    public function testFinancingTrendsGroupedByFundingTypeMatchesDefaultTotals(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends?groupBy=fundingType', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(300.0, $data[0]['groups']['public']);
        self::assertEquals(100.0, $data[0]['groups']['private']);
        self::assertEquals(400.0, $data[0]['total']);
        self::assertEquals(400.0, $data[1]['groups']['multilateral']);
    }

    public function testFinancingTrendsGroupedByCountryAggregatesCorrectly(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client); // Senegal: 400 in 2024

        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $this->entityManager->persist($kenya);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);
        $this->entityManager->persist(new Funding($kenya, $renewableEnergy, 2024, '200.00', FundingType::Public, $source, new \DateTimeImmutable('2024-05-01'), ValidationStatus::Validated));
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/financing-trends?groupBy=country', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertSame(2024, $data[0]['period']);
        self::assertEquals(400.0, $data[0]['groups']['SEN']);
        self::assertEquals(200.0, $data[0]['groups']['KEN']);
        self::assertEquals(600.0, $data[0]['total']);
    }

    /**
     * C1.6: every seedDataset() row defaults to FundingInstrument::Unknown
     * (never set explicitly) - groupBy=instrument must fold them all under
     * that single key rather than, say, silently dropping unset instruments.
     */
    public function testFinancingTrendsGroupedByInstrumentFoldsUnsetRowsUnderUnknown(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends?groupBy=instrument', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(400.0, $data[0]['groups']['unknown']);
        self::assertSame(['unknown'], array_keys($data[0]['groups']));
    }

    public function testFinancingTrendsRejectsAnInvalidGroupByValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/financing-trends?groupBy=not-a-real-dimension', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testFinancingTrendsGroupedBySectorAndDefaultUseDifferentCacheKeys(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $redis = $this->redisClient();

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);
        $client->request('GET', '/api/analytics/financing-trends?groupBy=sector', server: $auth);

        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_FINANCING_TRENDS), 'the default call must use its own plain cache key');
        self::assertNotNull($this->findCacheKey($redis, 'groupBy=sector'), 'the grouped call must have its own cache entry');
    }

    /**
     * C1.6: `movingAverageTotal` is a trailing 3-year average of `total` -
     * adds a third year (2023) to seedDataset()'s 2024/2025 so the window
     * actually fills up: 2023 alone -> 100; 2023+2024 -> (100+400)/2=250;
     * 2023+2024+2025 -> (100+400+400)/3=300.
     */
    public function testFinancingTrendsIncludesAThreeYearMovingAverageOfTheTotal(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);
        $this->entityManager->persist(new Funding($this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']), $agriculture, 2023, '100.00', FundingType::Public, $source, new \DateTimeImmutable('2023-03-15'), ValidationStatus::Validated));
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);

        $movingAverage = json_decode($client->getResponse()->getContent(), true)['movingAverageTotal'];
        self::assertSame([2023, 2024, 2025], array_column($movingAverage, 'period'));
        self::assertEquals(100.0, $movingAverage[0]['value']);
        self::assertEquals(250.0, $movingAverage[1]['value']);
        self::assertEquals(300.0, $movingAverage[2]['value']);
    }

    public function testSectorDistributionAggregatesAndComputesPercentage(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/sector-distribution', server: $auth);

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

    public function testInstrumentDistributionRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/instrument-distribution');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testInstrumentDistributionSucceedsForAnAuthenticatedUser(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/instrument-distribution', server: $auth);

        self::assertResponseIsSuccessful();
    }

    /**
     * C1.7: seedDataset()'s 3 rows all default to FundingInstrument::Unknown
     * (800 total) - adds one explicit Grant row (200, a fresh year to avoid
     * the dedup unique constraint) so the distribution has two real buckets.
     */
    public function testInstrumentDistributionAggregatesAndComputesPercentage(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $senegal = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);

        $grantFunded = new Funding($senegal, $renewableEnergy, 2026, '200.00', FundingType::Public, $source, new \DateTimeImmutable('2026-01-01'), ValidationStatus::Validated);
        $grantFunded->setInstrument(FundingInstrument::Grant);
        $this->entityManager->persist($grantFunded);
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/instrument-distribution', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        $byInstrument = array_column($data, null, 'instrument');
        self::assertEquals(800.0, $byInstrument['unknown']['amount']);
        self::assertEquals(80.0, $byInstrument['unknown']['percentage']);
        self::assertEquals(200.0, $byInstrument['grant']['amount']);
        self::assertEquals(20.0, $byInstrument['grant']['percentage']);
    }

    public function testInstrumentDistributionCrossTabsBySectorAndCountry(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $senegal = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);

        $grantFunded = new Funding($senegal, $renewableEnergy, 2026, '200.00', FundingType::Public, $source, new \DateTimeImmutable('2026-01-01'), ValidationStatus::Validated);
        $grantFunded->setInstrument(FundingInstrument::Grant);
        $this->entityManager->persist($grantFunded);
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/instrument-distribution', server: $auth);
        $body = json_decode($client->getResponse()->getContent(), true);

        $grantSectorRow = current(array_filter($body['bySector'], static fn (array $r): bool => 'grant' === $r['instrument']));
        self::assertNotFalse($grantSectorRow);
        self::assertSame('Renewable Energy', $grantSectorRow['sector']);
        self::assertEquals(200.0, $grantSectorRow['amount']);

        $grantCountryRow = current(array_filter($body['byCountry'], static fn (array $r): bool => 'grant' === $r['instrument']));
        self::assertNotFalse($grantCountryRow);
        self::assertSame('SEN', $grantCountryRow['isoCode']);
        self::assertEquals(200.0, $grantCountryRow['amount']);
    }

    public function testInstrumentDistributionIsServedFromRedisCacheOnSecondCall(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $redis = $this->redisClient();

        $client->request('GET', '/api/analytics/instrument-distribution', server: $auth);
        self::assertResponseIsSuccessful();

        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_INSTRUMENT_DISTRIBUTION), 'first request must populate Redis');
    }

    public function testFundingFlowsRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/funding-flows');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testFundingFlowsSucceedsForAnAuthenticatedUser(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/funding-flows', server: $auth);

        self::assertResponseIsSuccessful();
    }

    /**
     * C1.5: 2 sources x 2 countries x 2 sectors, all real amounts distinct
     * enough to verify link values precisely. topN defaults to 8, so
     * nothing here is bucketed into "Autres" - that's covered by its own
     * dedicated test below.
     */
    private function seedFlowDataset(KernelBrowser $client): array
    {
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $sourceA = new Source('Flow Source A', SourceType::OfficialApi, SourceReliability::High);
        $sourceB = new Source('Flow Source B', SourceType::OfficialApi, SourceReliability::High);
        $senegal = new Country('Flow Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Flow Kenya', 'KEN', "Afrique de l'Est");
        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        $user = new User('Amina Diallo', 'flows@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);

        foreach ([$sourceA, $sourceB, $senegal, $kenya, $renewableEnergy, $agriculture, $user] as $entity) {
            $this->entityManager->persist($entity);
        }

        // A -> Senegal -> Renewable Energy : 100
        $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2024, '100.00', FundingType::Public, $sourceA, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        // A -> Kenya -> Agriculture : 200
        $this->entityManager->persist(new Funding($kenya, $agriculture, 2024, '200.00', FundingType::Public, $sourceA, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        // B -> Senegal -> Agriculture : 300
        $this->entityManager->persist(new Funding($senegal, $agriculture, 2025, '300.00', FundingType::Multilateral, $sourceB, new \DateTimeImmutable('2025-01-01'), ValidationStatus::Validated));

        $this->entityManager->flush();
        $container->get('cache.analytics')->clear();

        return ['sourceA' => $sourceA, 'sourceB' => $sourceB, 'senegal' => $senegal, 'kenya' => $kenya, 'renewableEnergy' => $renewableEnergy, 'agriculture' => $agriculture, 'auth' => $this->loginAs($client, $user)];
    }

    public function testFundingFlowsBuildsNodesAndLinksFromRealData(): void
    {
        $client = static::createClient();
        $entities = $this->seedFlowDataset($client);

        $client->request('GET', '/api/analytics/funding-flows', server: $entities['auth']);

        $data = json_decode($client->getResponse()->getContent(), true);
        $nodeIds = array_column($data['nodes'], 'id');
        self::assertContains('source:'.$entities['sourceA']->getId(), $nodeIds);
        self::assertContains('source:'.$entities['sourceB']->getId(), $nodeIds);
        self::assertContains('country:SEN', $nodeIds);
        self::assertContains('country:KEN', $nodeIds);
        self::assertContains('sector:'.$entities['renewableEnergy']->getId(), $nodeIds);
        self::assertContains('sector:'.$entities['agriculture']->getId(), $nodeIds);
        self::assertArrayNotHasKey('source:other', array_column($data['nodes'], null, 'id'));

        $linksByPair = [];
        foreach ($data['links'] as $link) {
            $linksByPair[$link['source'].'->'.$link['target']] = $link['value'];
        }
        self::assertEquals(100.0, $linksByPair['source:'.$entities['sourceA']->getId().'->country:SEN']);
        self::assertEquals(200.0, $linksByPair['source:'.$entities['sourceA']->getId().'->country:KEN']);
        self::assertEquals(300.0, $linksByPair['source:'.$entities['sourceB']->getId().'->country:SEN']);
        self::assertEquals(100.0, $linksByPair['country:SEN->sector:'.$entities['renewableEnergy']->getId()]);
        self::assertEquals(200.0, $linksByPair['country:KEN->sector:'.$entities['agriculture']->getId()]);
        self::assertEquals(300.0, $linksByPair['country:SEN->sector:'.$entities['agriculture']->getId()]);
    }

    public function testFundingFlowsBucketsBeyondTopNIntoAnOthersNodeWithARealCount(): void
    {
        $client = static::createClient();
        $entities = $this->seedFlowDataset($client); // 2 distinct sources

        $client->request('GET', '/api/analytics/funding-flows?topN=1', server: $entities['auth']);

        $data = json_decode($client->getResponse()->getContent(), true);
        $sourceNodes = array_column($data['nodes'], null, 'id');
        self::assertArrayHasKey('source:other', $sourceNodes, 'the smaller of the 2 sources must be bucketed');
        self::assertSame('Autres bailleurs (1)', $sourceNodes['source:other']['label']);

        $linksByPair = array_column($data['links'], 'value', null);
        $found = false;
        foreach ($data['links'] as $link) {
            if ('source:other' === $link['source'] && str_starts_with($link['target'], 'country:')) {
                $found = true;
            }
        }
        self::assertTrue($found, 'the bucketed source must still link into a country node');
    }

    public function testFundingFlowsFiltersByYear(): void
    {
        $client = static::createClient();
        $entities = $this->seedFlowDataset($client);

        $client->request('GET', '/api/analytics/funding-flows?year=2025', server: $entities['auth']);

        $data = json_decode($client->getResponse()->getContent(), true);
        $total = array_sum(array_column($data['links'], 'value'));
        // Only the 2025 row (Flow Source B -> Senegal -> Agriculture, 300)
        // contributes on each side -> 300 (source->country) + 300 (country->sector) = 600.
        self::assertEquals(600.0, $total);
    }

    public function testFundingFlowsRejectsAnInvalidTopNValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/funding-flows?topN=0', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testFundingFlowsRejectsAnInvalidInstrumentValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/funding-flows?instrument=not-a-real-instrument', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testFundingFlowsWithNoDataReturnsEmptyNodesAndLinksNotAnError(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $user = new User('Amina Diallo', 'flows-empty@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $container->get('cache.analytics')->clear();
        $auth = $this->loginAs($client, $user);

        $client->request('GET', '/api/analytics/funding-flows', server: $auth);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['nodes']);
        self::assertSame([], $data['links']);
    }

    public function testFundingFlowsIsServedFromRedisCacheOnSecondCall(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $redis = $this->redisClient();

        $client->request('GET', '/api/analytics/funding-flows', server: $auth);
        self::assertResponseIsSuccessful();

        // The cache key is "analytics_funding_flows|instrument=,year=,topN=8"
        // (FundingFlowCriteria::toCacheKeyFragment() appended after "|") -
        // findCacheKey() matches by suffix, so match on that trailing
        // fragment rather than the bare prefix constant.
        self::assertNotNull($this->findCacheKey($redis, 'topN=8'), 'first request must populate Redis');
    }

    public function testCountryDistributionRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/country-distribution');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCountryDistributionAggregatesAndComputesPercentage(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client); // Senegal: 300 + 100 + 400 = 800

        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $this->entityManager->persist($kenya);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);
        $this->entityManager->persist(new Funding($kenya, $renewableEnergy, 2024, '200.00', FundingType::Public, $source, new \DateTimeImmutable('2024-05-01'), ValidationStatus::Validated));
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/country-distribution', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(2, $data);

        // Senegal: 800, Kenya: 200, total 1000 -> largest first.
        self::assertSame('SEN', $data[0]['isoCode']);
        self::assertSame('Senegal', $data[0]['country']);
        self::assertEquals(800.0, $data[0]['amount']);
        self::assertEquals(80.0, $data[0]['percentage']);

        self::assertSame('KEN', $data[1]['isoCode']);
        self::assertEquals(200.0, $data[1]['amount']);
        self::assertEquals(20.0, $data[1]['percentage']);
    }

    /**
     * C1.2: sector/instrument/year/fundingType filters, all optional and
     * cumulable. Reuses seedDataset()'s 3 rows (Senegal only) plus one
     * Kenya/Renewable Energy/2024/public/grant row added here specifically
     * so every filter has an unambiguous single-country expectation.
     */
    public function testCountryDistributionFiltersBySector(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);

        $client->request('GET', '/api/analytics/country-distribution?sector='.$agriculture->getId(), server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('SEN', $data[0]['isoCode']);
        self::assertEquals(100.0, $data[0]['amount']); // only the Agriculture/private row
        self::assertEquals(100.0, $data[0]['percentage']);
    }

    public function testCountryDistributionFiltersByYear(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/country-distribution?year=2025', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('SEN', $data[0]['isoCode']);
        self::assertEquals(400.0, $data[0]['amount']); // only the 2025 multilateral row
    }

    public function testCountryDistributionFiltersByFundingType(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/country-distribution?fundingType=private', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('SEN', $data[0]['isoCode']);
        self::assertEquals(100.0, $data[0]['amount']); // only the Agriculture/private row
    }

    public function testCountryDistributionFiltersByInstrument(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client); // every seeded row defaults to FundingInstrument::Unknown

        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $this->entityManager->persist($kenya);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);
        $grantFunded = new Funding($kenya, $renewableEnergy, 2024, '200.00', FundingType::Public, $source, new \DateTimeImmutable('2024-05-01'), ValidationStatus::Validated);
        $grantFunded->setInstrument(FundingInstrument::Grant);
        $this->entityManager->persist($grantFunded);
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/country-distribution?instrument=grant', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('KEN', $data[0]['isoCode']);
        self::assertEquals(200.0, $data[0]['amount']);
        self::assertEquals(100.0, $data[0]['percentage']);
    }

    public function testCountryDistributionCombinesMultipleFiltersAsAnd(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        // year=2024 AND fundingType=private matches only the Agriculture
        // row (100) - year=2024 alone would also match the public 300 one.
        $client->request('GET', '/api/analytics/country-distribution?year=2024&fundingType=private', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertEquals(100.0, $data[0]['amount']);
    }

    public function testCountryDistributionRejectsAnUnknownInstrumentValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/country-distribution?instrument=not-a-real-instrument', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testCountryDistributionRejectsANonIntegerYear(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/country-distribution?year=not-a-year', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testCountryDistributionUsesASeparateCacheKeyPerFilterCombination(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $redis = $this->redisClient();

        $client->request('GET', '/api/analytics/country-distribution', server: $auth);
        $unfilteredData = json_decode($client->getResponse()->getContent(), true)['data'];

        $client->request('GET', '/api/analytics/country-distribution?year=2025', server: $auth);
        $filteredData = json_decode($client->getResponse()->getContent(), true)['data'];

        // Different results prove the filtered call was not served from the
        // unfiltered request's cache entry (800 total vs 400 for 2025 only).
        self::assertEquals(800.0, $unfilteredData[0]['amount']);
        self::assertEquals(400.0, $filteredData[0]['amount']);

        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_COUNTRY_DISTRIBUTION), 'unfiltered call must still use the plain, pre-C1.2 cache key');
        // findCacheKey() matches a *suffix* (Redis "KEYS *X"), which "year=2025"
        // isn't here - the fragment format always ends in "fundingType=<...>"
        // - so this checks the full key list for the substring directly.
        $allKeys = $redis->keys('*');
        self::assertNotEmpty(array_filter($allKeys, static fn (string $key): bool => str_contains($key, 'year=2025')), 'the filtered call must have its own cache entry');
    }

    public function testCo2ReductionRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/co2-reduction');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCo2ReductionExplicitlyReportsUnavailableRatherThanFabricatingAValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/co2-reduction', server: $auth);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['available']);
        self::assertNull($data['data']);
        self::assertArrayHasKey('reason', $data);
        self::assertNotSame('', $data['reason']);
    }

    public function testCo2ReductionComputesYearOverYearVariationOnCommonCountries(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $senegal = $entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $entityManager->persist($kenya);
        $source = new Source('Emission Source', SourceType::InternalDemo, SourceReliability::Medium);
        $entityManager->persist($source);

        // Senegal: 20 -> 15 (reduction of 5). Kenya only has a 2024 row (no
        // 2025 row) - must be excluded from both sides of the comparison,
        // never treated as a Kenya reduction to 0.
        $entityManager->persist(new Emission($senegal, 2024, '20.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Validated));
        $entityManager->persist(new Emission($senegal, 2025, '15.000', $source, new \DateTimeImmutable('2025-06-01'), ValidationStatus::Validated));
        $entityManager->persist(new Emission($kenya, 2024, '8.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Validated));
        $entityManager->flush();
        $container->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/co2-reduction', server: $auth);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['available']);
        self::assertEqualsWithDelta(5.0, $data['data']['reductionMt'], 0.001);
        self::assertSame(2025, $data['data']['latestYear']);
        self::assertSame(2024, $data['data']['previousYear']);
        self::assertSame(1, $data['data']['countriesCompared']); // Senegal only - Kenya excluded
    }

    public function testCo2ReductionReportsNegativeValueOnARealIncrease(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $senegal = $entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $source = new Source('Emission Source', SourceType::InternalDemo, SourceReliability::Medium);
        $entityManager->persist($source);
        $entityManager->persist(new Emission($senegal, 2024, '10.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Validated));
        $entityManager->persist(new Emission($senegal, 2025, '14.000', $source, new \DateTimeImmutable('2025-06-01'), ValidationStatus::Validated));
        $entityManager->flush();
        $container->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/co2-reduction', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['available']);
        // A real increase must be reported as a negative number, never
        // clamped to 0 or hidden.
        self::assertEqualsWithDelta(-4.0, $data['data']['reductionMt'], 0.001);
    }

    public function testDataFreshnessRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/data-freshness');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testDataFreshnessReturnsTheLastCollectionDatePerSource(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client); // "Test Source": 2024-03-15, 2024-06-01, 2025-01-10

        $client->request('GET', '/api/analytics/data-freshness', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $data['sources']);
        self::assertSame('Test Source', $data['sources'][0]['name']);
        self::assertSame('2025-01-10', $data['sources'][0]['lastCollectedAt']); // the MAX, not the first or last inserted
        self::assertSame('2025-01-10', $data['oldestCollectionAt']); // only one source, so it's also the oldest
    }

    public function testDataFreshnessIncludesEmissionSourcesAlongsideFundingSources(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $container = static::getContainer();
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $emissionSource = new Source('UN SDG Global Database', SourceType::OfficialApi, SourceReliability::High);
        $this->entityManager->persist($kenya);
        $this->entityManager->persist($emissionSource);
        $this->entityManager->persist(new Emission($kenya, 2025, '12.500', $emissionSource, new \DateTimeImmutable('2026-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();
        $container->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/data-freshness', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        $names = array_column($data['sources'], 'name');
        self::assertContains('Test Source', $names);
        self::assertContains('UN SDG Global Database', $names);
        self::assertCount(2, $data['sources']);
    }

    public function testDataFreshnessWithNoDataReturnsAnEmptyArrayNotAnError(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $user = new User('Amina Diallo', 'freshness-empty@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $container->get('cache.analytics')->clear();
        $auth = $this->loginAs($client, $user);

        $client->request('GET', '/api/analytics/data-freshness', server: $auth);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['sources']);
        self::assertNull($data['oldestCollectionAt']);
        self::assertSame(0, $data['staleSourceCount']);
    }

    public function testDataFreshnessIsServedFromRedisCacheOnSecondCall(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $redis = $this->redisClient();

        $client->request('GET', '/api/analytics/data-freshness', server: $auth);
        self::assertResponseIsSuccessful();

        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_DATA_FRESHNESS), 'first request must populate Redis');
    }

    public function testEmptyDatasetReturnsEmptyDataArraysNotAnError(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $user = new User('Amina Diallo', 'empty-dataset@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $container->get('cache.analytics')->clear();
        $auth = $this->loginAs($client, $user);

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);

        $client->request('GET', '/api/analytics/sector-distribution', server: $auth);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);

        $client->request('GET', '/api/analytics/country-distribution', server: $auth);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);

        $client->request('GET', '/api/analytics/instrument-distribution', server: $auth);
        self::assertResponseIsSuccessful();
        $instrumentBody = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $instrumentBody['data']);
        self::assertSame([], $instrumentBody['bySector']);
        self::assertSame([], $instrumentBody['byCountry']);

        // Audit post-C3 2026-10-01 : gaps/local-finance/equity/transparency
        // n'avaient aucun test HTTP (ni ici, ni ailleurs) - seule leur
        // couche service l'etait deja (GapDetectionServiceTest,
        // LocalFinanceServiceTest, EquityScoreServiceTest,
        // TransparencyScoreServiceTest).
        $client->request('GET', '/api/analytics/gaps', server: $auth);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);

        $client->request('GET', '/api/analytics/local-finance', server: $auth);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);

        $client->request('GET', '/api/analytics/equity', server: $auth);
        self::assertResponseIsSuccessful();
        $equityBody = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $equityBody['ranking']);
        // assertEquals, not assertSame: json_encode() drops the trailing
        // ".0" from a whole-number float, so 0.0 round-trips as the PHP
        // int 0 - same gotcha documented elsewhere in this file.
        self::assertEquals(0.0, $equityBody['giniIndex']);

        $client->request('GET', '/api/analytics/transparency', server: $auth);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true));
    }

    public function testGapsRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/analytics/gaps');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testGapsRejectsAnInvalidByValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/gaps?by=bogus', server: $auth);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testLocalFinanceRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/analytics/local-finance');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testLocalFinanceRejectsAnUnknownCountryCode(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/local-finance?country=ZZZ', server: $auth);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testEquityRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/analytics/equity');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testTransparencyRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/analytics/transparency');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAnalyticsEndpointsAreDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        foreach (['/api/analytics/financing-trends', '/api/analytics/sector-distribution', '/api/analytics/country-distribution', '/api/analytics/co2-reduction', '/api/analytics/hero-stats', '/api/analytics/data-freshness', '/api/analytics/instrument-distribution', '/api/analytics/gaps', '/api/analytics/local-finance', '/api/analytics/equity', '/api/analytics/transparency'] as $path) {
            self::assertArrayHasKey($path, $spec['paths']);
            self::assertArrayHasKey('get', $spec['paths'][$path]);
        }
    }

    public function testHeroStatsRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/analytics/hero-stats');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testHeroStatsMatchesTheSeededDataset(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/hero-stats', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['countriesCovered', 'sectorsTracked', 'fundingRecords', 'activeSources'], array_keys($data));
        self::assertSame(1, $data['countriesCovered']); // only Senegal
        self::assertSame(2, $data['sectorsTracked']); // Renewable Energy + Agriculture
        self::assertSame(3, $data['fundingRecords']); // one summed row per (sector, year, fundingType) group
        self::assertSame(1, $data['activeSources']); // only "Test Source"
    }

    public function testHeroStatsWithNoDataReturnsZeroesNotAnError(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $user = new User('Amina Diallo', 'hero-empty@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $container->get('cache.analytics')->clear();
        $auth = $this->loginAs($client, $user);

        $client->request('GET', '/api/analytics/hero-stats', server: $auth);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(0, $data['countriesCovered']);
        self::assertSame(0, $data['sectorsTracked']);
        self::assertSame(0, $data['fundingRecords']);
        self::assertSame(0, $data['activeSources']);
    }

    public function testHeroStatsIsServedFromRedisCacheOnSecondCall(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $redis = $this->redisClient();

        $client->request('GET', '/api/analytics/hero-stats', server: $auth);
        self::assertResponseIsSuccessful();

        self::assertNotNull($this->findCacheKey($redis, 'analytics_hero_stats'), 'first request must populate Redis');
    }

    public function testFirstRequestPopulatesTheRedisCacheWithA900SecondTtl(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $redis = $this->redisClient();

        self::assertNull($this->findCacheKey($redis, self::CACHE_KEY_FINANCING_TRENDS), 'key must not exist before the first request');

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);
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
        $auth = $this->seedDataset($client);
        $redis = $this->redisClient();

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);
        $client->request('GET', '/api/analytics/sector-distribution', server: $auth);
        $client->request('GET', '/api/analytics/country-distribution', server: $auth);
        $client->request('GET', '/api/analytics/co2-reduction', server: $auth);
        $client->request('GET', '/api/analytics/instrument-distribution', server: $auth);

        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_FINANCING_TRENDS));
        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_SECTOR_DISTRIBUTION));
        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_COUNTRY_DISTRIBUTION));
        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_CO2_REDUCTION));
        self::assertNotNull($this->findCacheKey($redis, self::CACHE_KEY_INSTRUMENT_DISTRIBUTION));
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
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/analytics/sector-distribution', server: $auth);
        $firstResponse = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(700.0, $firstResponse[0]['amount']);

        // Mutate the dataset directly, bypassing AnalyticsService entirely. Year 2023 (not
        // used by seedDataset) keeps this a fresh dedup key - reusing 2024/Public here would
        // collide with the B1.1 dedup constraint's now-single summed row for that group.
        $senegal = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);
        $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2023, '999999.00', FundingType::Public, $source, new \DateTimeImmutable('2023-03-15'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $client->request('GET', '/api/analytics/sector-distribution', server: $auth);
        $secondResponse = json_decode($client->getResponse()->getContent(), true)['data'];

        self::assertEquals(700.0, $secondResponse[0]['amount'], 'must still be the cached pre-change value, not a recomputed one');
    }

    public function testHistorizedRowsAreExcludedFromEveryAggregate(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        // A superseded (historized) row sharing the exact same dedup key as
        // an existing current row from seedDataset() (Senegal/Renewable
        // Energy/2024/public/"Test Source") - a real revision scenario, not
        // a hypothetical one (see the 2026-08-31 idempotency fix spec). Its
        // large amount would visibly change every aggregate below if it
        // weren't excluded.
        $senegal = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);
        $historized = new Funding($senegal, $renewableEnergy, 2024, '999999.00', FundingType::Public, $source, new \DateTimeImmutable('2024-03-15'), ValidationStatus::Validated);
        $historized->setIsCurrent(false);
        $this->entityManager->persist($historized);
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);
        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(300.0, $data[0]['public']); // unchanged, not 999999 + 300

        $client->request('GET', '/api/analytics/sector-distribution', server: $auth);
        $sectorData = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(700.0, $sectorData[0]['amount']); // unchanged, not 999999 + 700

        $client->request('GET', '/api/analytics/country-distribution', server: $auth);
        $countryData = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(800.0, $countryData[0]['amount']); // unchanged, not 999999 + 800

        $client->request('GET', '/api/analytics/hero-stats', server: $auth);
        $heroData = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(3, $heroData['fundingRecords']); // unchanged, not 4
    }

    public function testDemoRowsAreExcludedFromEveryAggregate(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        // A ValidationStatus::Demo row (A1.6 fixture kind) with a large amount -
        // B3.3: no demo data ever reaches the frontend, so none of the
        // aggregates nor the Hero counts move.
        $senegal = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);
        $source = new Source('Demo Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);
        $this->entityManager->persist(new Funding($senegal, $agriculture, 2024, '999999.00', FundingType::Private, $source, new \DateTimeImmutable('2024-03-15'), ValidationStatus::Demo));
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/financing-trends', server: $auth);
        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(100.0, $data[0]['private']); // unchanged, not 999999 + 100

        $client->request('GET', '/api/analytics/sector-distribution', server: $auth);
        $sectorData = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(100.0, $sectorData[1]['amount']); // Agriculture unchanged, not 999999 + 100

        $client->request('GET', '/api/analytics/hero-stats', server: $auth);
        $heroData = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(3, $heroData['fundingRecords']); // unchanged, not 4
        self::assertSame(1, $heroData['activeSources']); // "Demo Source" not counted
    }
}
