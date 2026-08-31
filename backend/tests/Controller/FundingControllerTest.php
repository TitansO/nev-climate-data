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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Dataset seeded by seedDataset() for every test (25 Funding records):
 *   - Group A: Senegal / Renewable Energy / 2025 / public       -> 10 records, collectionDate 2025-03-15
 *   - Group B: Senegal / Renewable Energy / 2025 / private      ->  5 records, collectionDate 2025-03-15
 *   - Group C: Kenya   / Agriculture       / 2024 / private     -> 10 records, collectionDate 2024-06-01
 * Chosen so every single filter (country/sector/year/fundingType/period) and
 * a cumulative combination each isolate a distinct, predictable count:
 * country=SEN -> 15 (A+B); sector=Agriculture -> 10 (C); year=2025 -> 15 (A+B);
 * fundingType=private -> 15 (B+C); periodStart=2025-01-01 -> 15 (A+B);
 * periodEnd=2024-12-31 -> 10 (C); country=SEN&fundingType=private -> 5 (B only,
 * narrower than either filter alone); country=SEN&sector=Agriculture -> 0.
 */
final class FundingControllerTest extends WebTestCase
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
        parent::tearDown();
    }

    private function seedDataset(KernelBrowser $client): void
    {
        $client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest", 'XOF');
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');

        foreach ([$senegal, $kenya, $renewableEnergy, $agriculture] as $entity) {
            $this->entityManager->persist($entity);
        }

        // Each row below gets its own Source: the B1.1 dedup constraint (unique per
        // source/country/sector/year/fundingType among *current* rows — see the
        // #[ORM\UniqueConstraint] on Funding::class) means the rows within a group that share
        // one (country, sector, year, fundingType) tuple can no longer also share one Source
        // row. Source identity itself is asserted only once in this suite
        // (testResponseShapeMatchesContractAndHidesInternalFields, via
        // `?country=SEN&fundingType=public&limit=1`) — the list endpoint orders by
        // collectionDate DESC, then id DESC (see FundingRepository), so within a
        // same-collectionDate group the *last*-inserted row is the one returned for limit=1.
        // Group A's last row keeps the exact original name "Test Source" for that reason;
        // every other row's Source name is otherwise never asserted on, so any distinct name
        // satisfies the constraint.
        for ($i = 0; $i < 10; ++$i) {
            $name = 9 === $i ? 'Test Source' : "Test Source A{$i}";
            $source = new Source($name, SourceType::InternalDemo, SourceReliability::Medium);
            $this->entityManager->persist($source);
            $funding = new Funding(
                $senegal,
                $renewableEnergy,
                2025,
                '1000000.00',
                FundingType::Public,
                $source,
                new \DateTimeImmutable('2025-03-15'),
                ValidationStatus::Demo,
            );
            // Only on the exact row testResponseShapeMatchesContractAndHidesInternalFields
            // asserts on (the "Test Source" one, i === 9) - every other row in this suite
            // is checked by count/filter only, never by originalAmount/originalCurrency value.
            if (9 === $i) {
                $funding->setOriginalAmount('600000000.00')->setOriginalCurrency('XOF');
            }
            $this->entityManager->persist($funding);
        }

        for ($i = 0; $i < 5; ++$i) {
            $source = new Source("Test Source B{$i}", SourceType::InternalDemo, SourceReliability::Medium);
            $this->entityManager->persist($source);
            $this->entityManager->persist(new Funding(
                $senegal,
                $renewableEnergy,
                2025,
                '500000.00',
                FundingType::Private,
                $source,
                new \DateTimeImmutable('2025-03-15'),
                ValidationStatus::Demo,
            ));
        }

        for ($i = 0; $i < 10; ++$i) {
            $source = new Source("Test Source C{$i}", SourceType::InternalDemo, SourceReliability::Medium);
            $this->entityManager->persist($source);
            $this->entityManager->persist(new Funding(
                $kenya,
                $agriculture,
                2024,
                '250000.00',
                FundingType::Private,
                $source,
                new \DateTimeImmutable('2024-06-01'),
                ValidationStatus::Demo,
            ));
        }

        $this->entityManager->flush();
    }

    private function sectorId(string $name): int
    {
        /** @var Sector $sector */
        $sector = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => $name]);

        return $sector->getId();
    }

    public function testFundingListReturns200AndIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        // No Authorization header, no X-API-Key — a visitor with no account.
        $client->request('GET', '/api/funding');

        self::assertResponseIsSuccessful();
    }

    public function testDefaultPaginationMeta(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(20, $data['data']);
        self::assertSame(1, $data['meta']['page']);
        self::assertSame(20, $data['meta']['limit']);
        self::assertSame(25, $data['meta']['total']);
        self::assertSame(2, $data['meta']['totalPages']);
    }

    public function testCustomPagination(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?page=2&limit=10');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(10, $data['data']);
        self::assertSame(2, $data['meta']['page']);
        self::assertSame(10, $data['meta']['limit']);
        self::assertSame(25, $data['meta']['total']);
        self::assertSame(3, $data['meta']['totalPages']);
    }

    public function testLimitAboveMaximumIsClampedNotRejected(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?limit=500');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(100, $data['meta']['limit']);
        self::assertCount(25, $data['data']);
    }

    public function testFilterByCountry(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?country=SEN&limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(15, $data['meta']['total']);
        foreach ($data['data'] as $item) {
            self::assertSame('SEN', $item['country']['isoCode']);
        }
    }

    public function testFilterBySector(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?sector='.$this->sectorId('Agriculture').'&limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(10, $data['meta']['total']);
        foreach ($data['data'] as $item) {
            self::assertSame('Agriculture', $item['sector']['name']);
        }
    }

    public function testFilterByYear(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?year=2025&limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(15, $data['meta']['total']);
        foreach ($data['data'] as $item) {
            self::assertSame(2025, $item['year']);
        }
    }

    public function testFilterByFundingType(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?fundingType=private&limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(15, $data['meta']['total']);
        foreach ($data['data'] as $item) {
            self::assertSame('private', $item['fundingType']);
        }
    }

    public function testFilterByPeriodStart(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?periodStart=2025-01-01&limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(15, $data['meta']['total']);
    }

    public function testFilterByPeriodEnd(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?periodEnd=2024-12-31&limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(10, $data['meta']['total']);
    }

    public function testCumulativeFiltersNarrowTheResultBelowEitherFilterAlone(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?country=SEN&fundingType=private&limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(5, $data['meta']['total']);
        foreach ($data['data'] as $item) {
            self::assertSame('SEN', $item['country']['isoCode']);
            self::assertSame('private', $item['fundingType']);
        }
    }

    public function testNoResultsReturnsEmptyDataWithCorrectMeta(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?country=SEN&sector='.$this->sectorId('Agriculture'));

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['data']);
        self::assertSame(0, $data['meta']['total']);
        self::assertSame(0, $data['meta']['totalPages']);
    }

    public function testInvalidFundingTypeReturns400Json(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?fundingType=invalid');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
        self::assertArrayHasKey('message', $data);
    }

    public function testInvalidDateReturns400Json(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?periodStart=not-a-date');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
    }

    public function testPeriodStartAfterPeriodEndReturns400Json(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?periodStart=2025-12-31&periodEnd=2022-01-01');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
    }

    public function testInvalidPageReturns400Json(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?page=0');
        self::assertSame(400, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/funding?page=-1');
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
    }

    public function testInvalidLimitReturns400Json(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?limit=0');
        self::assertSame(400, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/funding?limit=-10');
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
    }

    public function testResponseShapeMatchesContractAndHidesInternalFields(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding?country=SEN&fundingType=public&limit=1');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $data['data']);
        $item = $data['data'][0];

        self::assertSame(
            ['id', 'country', 'sector', 'year', 'amount', 'originalAmount', 'originalCurrency', 'fundingType', 'source', 'collectionDate', 'validationStatus'],
            array_keys($item),
        );
        self::assertSame(['name', 'isoCode', 'currency'], array_keys($item['country']));
        self::assertSame('Senegal', $item['country']['name']);
        self::assertSame('SEN', $item['country']['isoCode']);
        self::assertSame('XOF', $item['country']['currency']);
        self::assertSame(['id', 'name'], array_keys($item['sector']));
        self::assertSame('Renewable Energy', $item['sector']['name']);
        self::assertSame(['id', 'name'], array_keys($item['source']));
        self::assertSame('Test Source', $item['source']['name']);
        self::assertSame('1000000.00', $item['amount']);
        // Same amount, in Senegal's own currency (A2.x's "Montant (devise
        // locale)" data.html column) - see the seedDataset() row this
        // request resolves to (i === 9, the only one with these set).
        self::assertSame('600000000.00', $item['originalAmount']);
        self::assertSame('XOF', $item['originalCurrency']);
        self::assertSame('public', $item['fundingType']);
        self::assertSame('2025-03-15', $item['collectionDate']);
        self::assertSame('demo', $item['validationStatus']);

        // exchangeRate is raw pivot-conversion metadata, not meant for display -
        // stays internal even though originalAmount/originalCurrency (above) are
        // now exposed for it. Timestamps/historization fields likewise never leak.
        foreach (['exchangeRate', 'validFrom', 'validTo', 'isCurrent', 'createdAt', 'updatedAt'] as $internalField) {
            self::assertArrayNotHasKey($internalField, $item);
        }

        self::assertSame(['page', 'limit', 'total', 'totalPages'], array_keys($data['meta']));
    }

    public function testHistorizedRowsAreExcludedFromListingsAndCounts(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        // A superseded (historized) row satisfying the same sector filter as
        // Group C (Kenya/Agriculture/2024/private, 10 records) - must never be
        // counted or listed, even though it matches every filter below.
        $kenya = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'KEN']);
        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);
        $source = new Source('Test Source Historized', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);
        $historized = new Funding(
            $kenya,
            $agriculture,
            2024,
            '9999999.00',
            FundingType::Private,
            $source,
            new \DateTimeImmutable('2024-06-01'),
            ValidationStatus::Demo,
        );
        $historized->setIsCurrent(false);
        $this->entityManager->persist($historized);
        $this->entityManager->flush();

        $client->request('GET', '/api/funding?sector='.$this->sectorId('Agriculture').'&limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(10, $data['meta']['total']); // unchanged - the historized row never counts
        foreach ($data['data'] as $item) {
            self::assertNotSame('9999999.00', $item['amount']);
        }
    }
}
