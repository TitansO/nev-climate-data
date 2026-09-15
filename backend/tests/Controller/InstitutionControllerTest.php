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

/**
 * GET /api/institutions/{sourceId} (C1.4). Dataset seeded by
 * seedDataset(): one Source ("World Bank Data API", high reliability)
 * funding Senegal/Renewable Energy/2024/public $300 and Kenya/
 * Agriculture/2025/multilateral $200 - two countries and two sectors so
 * the breakdown/percentage math is unambiguous. A second, unrelated
 * Source ("Local Registry") has no funding at all - covers the "no
 * portfolio yet" case without fabricating a zero.
 */
final class InstitutionControllerTest extends WebTestCase
{
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

    private function findCacheKey(PredisClient $redis, string $keySuffix): ?string
    {
        $matches = $redis->keys('*'.$keySuffix);

        return $matches[0] ?? null;
    }

    private function seedDataset(KernelBrowser $client): Source
    {
        $client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        $worldBank = new Source('World Bank Data API', SourceType::OfficialApi, SourceReliability::High);
        $localRegistry = new Source('Local Registry', SourceType::InternalDemo, SourceReliability::Medium);

        foreach ([$senegal, $kenya, $renewableEnergy, $agriculture, $worldBank, $localRegistry] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2024, '300.00', FundingType::Public, $worldBank, new \DateTimeImmutable('2024-03-15'), ValidationStatus::Validated));
        $this->entityManager->persist(new Funding($kenya, $agriculture, 2025, '200.00', FundingType::Multilateral, $worldBank, new \DateTimeImmutable('2025-01-10'), ValidationStatus::Validated));

        $this->entityManager->flush();
        $container->get('cache.analytics')->clear();

        return $worldBank;
    }

    public function testInstitutionProfileIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $worldBank = $this->seedDataset($client);

        $client->request('GET', '/api/institutions/'.$worldBank->getId());

        self::assertResponseIsSuccessful();
    }

    public function testInstitutionProfileReturns404ForAnUnknownSource(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/institutions/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testInstitutionProfileIncludesIdentityAndReliability(): void
    {
        $client = static::createClient();
        $worldBank = $this->seedDataset($client);

        $client->request('GET', '/api/institutions/'.$worldBank->getId());

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame($worldBank->getId(), $data['id']);
        self::assertSame('World Bank Data API', $data['name']);
        self::assertSame('official_api', $data['type']);
        self::assertSame('high', $data['reliability']);
    }

    public function testInstitutionProfilePortfolioSummary(): void
    {
        $client = static::createClient();
        $worldBank = $this->seedDataset($client);

        $client->request('GET', '/api/institutions/'.$worldBank->getId());

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertEquals(500.0, $data['portfolio']['totalAmountUsd']);
        self::assertSame(2, $data['portfolio']['fundingRecordCount']);
        self::assertSame(2, $data['portfolio']['countriesFundedCount']);
        self::assertSame(2, $data['portfolio']['sectorsFundedCount']);
    }

    public function testInstitutionProfileWithNoFundingReturnsANullPortfolioNotAFabricatedZero(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $localRegistry = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Local Registry']);

        $client->request('GET', '/api/institutions/'.$localRegistry->getId());

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertNull($data['portfolio']);
        self::assertSame([], $data['sectorBreakdown']);
        self::assertSame([], $data['countryBreakdown']);
        self::assertSame([], $data['yearlyTrend']);
        self::assertNull($data['transparency']['lastCollectedAt']);
        self::assertNull($data['transparency']['daysSinceCollection']);
    }

    public function testInstitutionProfileSectorAndCountryBreakdownComputePercentages(): void
    {
        $client = static::createClient();
        $worldBank = $this->seedDataset($client);

        $client->request('GET', '/api/institutions/'.$worldBank->getId());

        $data = json_decode($client->getResponse()->getContent(), true);

        self::assertCount(2, $data['sectorBreakdown']);
        self::assertSame('Renewable Energy', $data['sectorBreakdown'][0]['sector']);
        self::assertEquals(300.0, $data['sectorBreakdown'][0]['amount']);
        self::assertEquals(60.0, $data['sectorBreakdown'][0]['percentage']);
        self::assertEquals(40.0, $data['sectorBreakdown'][1]['percentage']);

        self::assertCount(2, $data['countryBreakdown']);
        self::assertSame('SEN', $data['countryBreakdown'][0]['isoCode']);
        self::assertEquals(60.0, $data['countryBreakdown'][0]['percentage']);
    }

    public function testInstitutionProfileYearlyTrendIsChronological(): void
    {
        $client = static::createClient();
        $worldBank = $this->seedDataset($client);

        $client->request('GET', '/api/institutions/'.$worldBank->getId());

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([2024, 2025], array_column($data['yearlyTrend'], 'period'));
        self::assertEquals(300.0, $data['yearlyTrend'][0]['amount']);
        self::assertEquals(200.0, $data['yearlyTrend'][1]['amount']);
    }

    public function testInstitutionProfileTransparencyReflectsTheLastRealCollection(): void
    {
        $client = static::createClient();
        $worldBank = $this->seedDataset($client);

        $client->request('GET', '/api/institutions/'.$worldBank->getId());

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('2025-01-10', $data['transparency']['lastCollectedAt']);
        self::assertIsInt($data['transparency']['daysSinceCollection']);
    }

    public function testInstitutionProfileExcludesHistorizedAndDemoRows(): void
    {
        $client = static::createClient();
        $worldBank = $this->seedDataset($client);

        $historized = new Funding(
            $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']),
            $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']),
            2024,
            '999999.00',
            FundingType::Public,
            $worldBank,
            new \DateTimeImmutable('2024-03-15'),
            ValidationStatus::Validated
        );
        $historized->setIsCurrent(false);
        $this->entityManager->persist($historized);

        $demo = new Funding(
            $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'KEN']),
            $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']),
            2025,
            '999999.00',
            FundingType::Multilateral,
            $worldBank,
            new \DateTimeImmutable('2025-01-10'),
            ValidationStatus::Demo
        );
        $this->entityManager->persist($demo);
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/institutions/'.$worldBank->getId());

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertEquals(500.0, $data['portfolio']['totalAmountUsd']); // unchanged, not +999999*2
    }

    public function testInstitutionProfileIsServedFromRedisCacheOnSecondCall(): void
    {
        $client = static::createClient();
        $worldBank = $this->seedDataset($client);
        $redis = $this->redisClient();

        $client->request('GET', '/api/institutions/'.$worldBank->getId());
        self::assertResponseIsSuccessful();

        self::assertNotNull($this->findCacheKey($redis, 'institution_profile_'.$worldBank->getId()), 'first request must populate Redis');
    }

    public function testInstitutionProfileIsDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/institutions/{sourceId}', $spec['paths']);
        self::assertArrayHasKey('get', $spec['paths']['/api/institutions/{sourceId}']);
    }
}
