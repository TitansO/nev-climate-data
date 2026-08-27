<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\Enum\ReportStatus;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Report;
use App\Entity\Sector;
use App\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/search (A2.8). Dataset seeded by seedDataset():
 *   - Countries: Senegal (SEN), Kenya (KEN)
 *   - Sectors: Renewable Energy, Agriculture
 *   - Sources: World Bank Data API, Local Registry
 *   - Reports: "Senegal Climate Finance Report" (Published),
 *              "Senegal Draft Notes" (Draft - must never appear)
 */
final class SearchControllerTest extends WebTestCase
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

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        $worldBank = new Source('World Bank Data API', SourceType::OfficialApi, SourceReliability::High);
        $localRegistry = new Source('Local Registry', SourceType::InternalDemo, SourceReliability::Medium);

        $published = new Report('Senegal Climate Finance Report', 'country', 'reports/senegal-2025.pdf');
        $published->setStatus(ReportStatus::Published);
        $draft = new Report('Senegal Draft Notes', 'country', 'reports/senegal-draft.pdf');
        // Left in Draft (constructor default) - must never appear in search results.

        foreach ([$senegal, $kenya, $renewableEnergy, $agriculture, $worldBank, $localRegistry, $published, $draft] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function testMissingQueryReturns400(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
    }

    public function testEmptyQueryReturns400(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testWhitespaceOnlyQueryReturns400(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q='.urlencode('   '));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTooShortQueryReturns400(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=a');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTooLongQueryReturns400(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q='.str_repeat('a', 101));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSearchIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=senegal');

        self::assertResponseIsSuccessful();
    }

    public function testSearchFindsACountryCaseInsensitively(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=SeNeGaL');

        $data = json_decode($client->getResponse()->getContent(), true);
        // "query" echoes back what was typed (trimmed), not a normalized
        // form - matching stays case-insensitive regardless.
        self::assertSame('SeNeGaL', $data['query']);
        $countryResults = array_values(array_filter($data['results'], static fn (array $r) => 'country' === $r['type']));
        self::assertCount(1, $countryResults);
        self::assertSame('Senegal', $countryResults[0]['title']);
        self::assertSame('data.html?country=SEN', $countryResults[0]['destination']);
    }

    public function testSearchIgnoresLeadingAndTrailingWhitespace(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q='.urlencode('  senegal  '));

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('senegal', $data['query']);
        self::assertNotEmpty($data['results']);
    }

    public function testSearchFindsASector(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=renewable');

        $data = json_decode($client->getResponse()->getContent(), true);
        $sectorResults = array_values(array_filter($data['results'], static fn (array $r) => 'sector' === $r['type']));
        self::assertCount(1, $sectorResults);
        self::assertSame('Renewable Energy', $sectorResults[0]['title']);
    }

    public function testSearchFindsASource(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=world+bank');

        $data = json_decode($client->getResponse()->getContent(), true);
        $sourceResults = array_values(array_filter($data['results'], static fn (array $r) => 'source' === $r['type']));
        self::assertCount(1, $sourceResults);
        self::assertSame('World Bank Data API', $sourceResults[0]['title']);
        self::assertSame('sources.html', $sourceResults[0]['destination']);
    }

    public function testSearchFindsAPublishedReportButNeverADraft(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=senegal');

        $data = json_decode($client->getResponse()->getContent(), true);
        $reportResults = array_values(array_filter($data['results'], static fn (array $r) => 'report' === $r['type']));
        self::assertCount(1, $reportResults, 'the Draft report must be excluded, only the Published one is expected');
        self::assertSame('Senegal Climate Finance Report', $reportResults[0]['title']);
    }

    public function testSearchAcrossMultipleTypesForTheSameTerm(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=senegal');

        $data = json_decode($client->getResponse()->getContent(), true);
        $types = array_unique(array_column($data['results'], 'type'));
        sort($types);
        self::assertSame(['country', 'report'], $types);
    }

    public function testSearchWithNoMatchesReturnsEmptyResultsNotAnError(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=zzzznomatchzzzz');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    public function testSpecialCharactersAreHandledSafelyWithoutActingAsWildcards(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        // A literal "%" or "_" in the query must not act as a SQL wildcard
        // and match everything - it must be searched for literally (and
        // find nothing here, since no seeded name contains it).
        $client->request('GET', '/api/search?q='.urlencode('%_test'));

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    public function testResultsWithinATypeAreAlphabeticallyOrdered(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=re'); // matches both sectors: "Renewable Energy" (starts with "Re"), "Agriculture" (ends with "re")

        $data = json_decode($client->getResponse()->getContent(), true);
        $sectorTitles = array_column(array_filter($data['results'], static fn (array $r) => 'sector' === $r['type']), 'title');
        $sorted = $sectorTitles;
        sort($sorted, \SORT_STRING | \SORT_FLAG_CASE);
        self::assertSame($sorted, array_values($sectorTitles));
    }

    public function testResultShapeNeverExposesUnexpectedFields(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search?q=senegal');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['results']);
        foreach ($data['results'] as $result) {
            self::assertSame(['type', 'id', 'title', 'description', 'destination'], array_keys($result));
        }
    }

    public function testSearchIsDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/search', $spec['paths']);
        self::assertArrayHasKey('get', $spec['paths']['/api/search']);
    }
}
