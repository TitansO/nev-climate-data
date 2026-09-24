<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\ReportStatus;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Opportunity;
use App\Entity\Project;
use App\Entity\Report;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/search (A2.8, C1.3). Dataset seeded by seedDataset():
 *   - Countries: Senegal (SEN), Kenya (KEN), Côte d'Ivoire (CIV - accented,
 *     for the accent-insensitivity tests)
 *   - Sectors: Renewable Energy, Agriculture
 *   - Sources: World Bank Data API, Local Registry
 *   - Reports: "Senegal Climate Finance Report" (Published),
 *              "Senegal Draft Notes" (Draft - must never appear)
 *   - Project: "Wind Corridor Expansion" (Kenya, Renewable Energy, grant,
 *     750000) - titled to never collide with another test's search term
 *   - Opportunity: "Coastal Resilience Facility" (World Bank, Agriculture,
 *     3000000, application window opens 2024-06-01) - same reasoning
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

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function seedDataset(KernelBrowser $client): array
    {
        $client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        // Structuration des accès (2026-09-24) : /api/search est désormais
        // ROLE_USER (auparavant PUBLIC_ACCESS).
        $user = new User('Amina Diallo', 'search@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        // Accented on purpose - see testSearchFindsAnAccentedCountryWithAnUnaccentedQuery().
        $coteDivoire = new Country("Côte d'Ivoire", 'CIV', "Afrique de l'Ouest");
        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        $worldBank = new Source('World Bank Data API', SourceType::OfficialApi, SourceReliability::High);
        $localRegistry = new Source('Local Registry', SourceType::InternalDemo, SourceReliability::Medium);

        $published = new Report('Senegal Climate Finance Report', 'country', 'reports/senegal-2025.pdf');
        $published->setStatus(ReportStatus::Published);
        $draft = new Report('Senegal Draft Notes', 'country', 'reports/senegal-draft.pdf');
        // Left in Draft (constructor default) - must never appear in search results.

        $project = new Project('Wind Corridor Expansion', $kenya, $renewableEnergy, '750000.00', 'A wind corridor project.', 'GreenCo');
        $project->setInstrumentSought(FundingInstrument::Grant);

        $opportunity = new Opportunity('Coastal Resilience Facility', $worldBank, 'Open to coastal adaptation projects.', '3000000.00');
        $opportunity->setSector($agriculture);
        $opportunity->setApplicationWindowOpensAt(new \DateTimeImmutable('2024-06-01'));

        foreach ([$senegal, $kenya, $coteDivoire, $renewableEnergy, $agriculture, $worldBank, $localRegistry, $published, $draft, $project, $opportunity] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'search@example.com', 'password' => 'correct-horse-battery-staple']));
        $token = json_decode($client->getResponse()->getContent(), true)['token'];

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    public function testMissingQueryRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/search');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testMissingQueryReturns400(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search', server: $auth);

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
    }

    public function testEmptyQueryReturns400(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=', server: $auth);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testWhitespaceOnlyQueryReturns400(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q='.urlencode('   '), server: $auth);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTooShortQueryReturns400(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=a', server: $auth);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTooLongQueryReturns400(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q='.str_repeat('a', 101), server: $auth);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSearchSucceedsForAnAuthenticatedUser(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=senegal', server: $auth);

        self::assertResponseIsSuccessful();
    }

    public function testSearchFindsACountryCaseInsensitively(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=SeNeGaL', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        // "query" echoes back what was typed (trimmed), not a normalized
        // form - matching stays case-insensitive regardless.
        self::assertSame('SeNeGaL', $data['query']);
        $countryResults = array_values(array_filter($data['results'], static fn (array $r) => 'country' === $r['type']));
        self::assertCount(1, $countryResults);
        self::assertSame('Senegal', $countryResults[0]['title']);
        self::assertSame('data.html?country=SEN', $countryResults[0]['destination']);
    }

    public function testSearchFindsAnAccentedCountryWithAnUnaccentedQuery(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        // Data has the accents ("Côte d'Ivoire"), the query doesn't.
        $client->request('GET', '/api/search?q='.urlencode("cote d'ivoire"), server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        $countryResults = array_values(array_filter($data['results'], static fn (array $r) => 'country' === $r['type']));
        self::assertCount(1, $countryResults);
        self::assertSame("Côte d'Ivoire", $countryResults[0]['title']);
        self::assertSame('data.html?country=CIV', $countryResults[0]['destination']);
    }

    public function testSearchFindsAnUnaccentedCountryWithAnAccentedQuery(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        // Reverse direction: the query has accents ("Sénégal"), the seeded
        // data doesn't ("Senegal").
        $client->request('GET', '/api/search?q='.urlencode('Sénégal'), server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        $countryResults = array_values(array_filter($data['results'], static fn (array $r) => 'country' === $r['type']));
        self::assertCount(1, $countryResults);
        self::assertSame('Senegal', $countryResults[0]['title']);
    }

    public function testSearchIgnoresLeadingAndTrailingWhitespace(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q='.urlencode('  senegal  '), server: $auth);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('senegal', $data['query']);
        self::assertNotEmpty($data['results']);
    }

    public function testSearchFindsASector(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=renewable', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        $sectorResults = array_values(array_filter($data['results'], static fn (array $r) => 'sector' === $r['type']));
        self::assertCount(1, $sectorResults);
        self::assertSame('Renewable Energy', $sectorResults[0]['title']);
    }

    public function testSearchFindsASource(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=world+bank', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        $sourceResults = array_values(array_filter($data['results'], static fn (array $r) => 'source' === $r['type']));
        self::assertCount(1, $sourceResults);
        self::assertSame('World Bank Data API', $sourceResults[0]['title']);
        self::assertSame('institution-profile.html?sourceId='.$sourceResults[0]['id'], $sourceResults[0]['destination']);
    }

    public function testSearchFindsAPublishedReportButNeverADraft(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=senegal', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        $reportResults = array_values(array_filter($data['results'], static fn (array $r) => 'report' === $r['type']));
        self::assertCount(1, $reportResults, 'the Draft report must be excluded, only the Published one is expected');
        self::assertSame('Senegal Climate Finance Report', $reportResults[0]['title']);
    }

    public function testSearchAcrossMultipleTypesForTheSameTerm(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=senegal', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        $types = array_unique(array_column($data['results'], 'type'));
        sort($types);
        self::assertSame(['country', 'report'], $types);
    }

    public function testSearchWithNoMatchesReturnsEmptyResultsNotAnError(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=zzzznomatchzzzz', server: $auth);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    public function testSpecialCharactersAreHandledSafelyWithoutActingAsWildcards(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        // A literal "%" or "_" in the query must not act as a SQL wildcard
        // and match everything - it must be searched for literally (and
        // find nothing here, since no seeded name contains it).
        $client->request('GET', '/api/search?q='.urlencode('%_test'), server: $auth);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    public function testResultsWithinATypeAreAlphabeticallyOrdered(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=re', server: $auth); // matches both sectors: "Renewable Energy" (starts with "Re"), "Agriculture" (ends with "re")

        $data = json_decode($client->getResponse()->getContent(), true);
        $sectorTitles = array_column(array_filter($data['results'], static fn (array $r) => 'sector' === $r['type']), 'title');
        $sorted = $sectorTitles;
        sort($sorted, \SORT_STRING | \SORT_FLAG_CASE);
        self::assertSame($sorted, array_values($sectorTitles));
    }

    public function testResultShapeNeverExposesUnexpectedFields(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=senegal', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['results']);
        foreach ($data['results'] as $result) {
            self::assertSame(['type', 'id', 'title', 'description', 'destination'], array_keys($result));
        }
    }

    public function testSearchFindsAProjectByTitle(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=wind+corridor', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        $projectResults = array_values(array_filter($data['results'], static fn (array $r) => 'project' === $r['type']));
        self::assertCount(1, $projectResults);
        self::assertSame('Wind Corridor Expansion', $projectResults[0]['title']);
        self::assertSame('dashboard.html', $projectResults[0]['destination']);
    }

    public function testSearchFindsAnOpportunityByTitle(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=coastal+resilience', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        $opportunityResults = array_values(array_filter($data['results'], static fn (array $r) => 'opportunity' === $r['type']));
        self::assertCount(1, $opportunityResults);
        self::assertSame('Coastal Resilience Facility', $opportunityResults[0]['title']);
        self::assertSame('dashboard.html', $opportunityResults[0]['destination']);
    }

    /**
     * C1.3: the "sector" facet only applies to Sector/Project/Opportunity -
     * Country has no sector of its own, so setting it excludes Country from
     * the results entirely, even though "kenya" alone would otherwise match it.
     */
    public function testSectorFacetExcludesCountryEntirely(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);

        $client->request('GET', '/api/search?q=kenya&sector='.$agriculture->getId(), server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    /**
     * C1.3: the "country" facet only applies to Country/Report/Project -
     * Sector has no country of its own, so setting it excludes Sector from
     * the results entirely, even though "agriculture" alone would otherwise
     * match it.
     */
    public function testCountryFacetExcludesSectorEntirely(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=agriculture&country=KEN', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    public function testInstrumentFacetNarrowsProjectResults(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=wind&instrument=grant', server: $auth);
        $matching = json_decode($client->getResponse()->getContent(), true)['results'];
        self::assertCount(1, $matching);

        $client->request('GET', '/api/search?q=wind&instrument=guarantee', server: $auth);
        $nonMatching = json_decode($client->getResponse()->getContent(), true)['results'];
        self::assertSame([], $nonMatching);
    }

    /**
     * C1.3: "instrument" only applies to Project - even a text match on
     * another type (Kenya) is excluded once this facet is set.
     */
    public function testInstrumentFacetExcludesCountryEntirely(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=kenya&instrument=grant', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    public function testYearFacetNarrowsOpportunityByItsApplicationWindow(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=coastal&year=2024', server: $auth);
        $matching = json_decode($client->getResponse()->getContent(), true)['results'];
        self::assertCount(1, $matching);

        $client->request('GET', '/api/search?q=coastal&year=2023', server: $auth);
        $nonMatching = json_decode($client->getResponse()->getContent(), true)['results'];
        self::assertSame([], $nonMatching);
    }

    public function testYearFacetNarrowsProjectByItsCreationYear(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $currentYear = (int) (new \DateTimeImmutable())->format('Y');

        $client->request('GET', '/api/search?q=wind&year='.$currentYear, server: $auth);
        $matching = json_decode($client->getResponse()->getContent(), true)['results'];
        self::assertCount(1, $matching);

        $client->request('GET', '/api/search?q=wind&year='.($currentYear - 1), server: $auth);
        $nonMatching = json_decode($client->getResponse()->getContent(), true)['results'];
        self::assertSame([], $nonMatching);
    }

    /**
     * C1.3: "year" excludes both Report (publicationDate is null on the
     * seeded report - never matches any year) and Country (no year
     * dimension at all) - "senegal" alone would otherwise match both.
     */
    public function testYearFacetExcludesAReportWithNoPublicationDateAndCountryEntirely(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=senegal&year=2025', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    public function testMinAmountFacetNarrowsProjectAndOpportunityResults(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=wind&minAmount=750000', server: $auth);
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true)['results']);

        $client->request('GET', '/api/search?q=wind&minAmount=750001', server: $auth);
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['results']);

        $client->request('GET', '/api/search?q=coastal&minAmount=3000000', server: $auth);
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true)['results']);
    }

    public function testMinAmountFacetExcludesCountryEntirely(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=kenya&minAmount=1', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    public function testInvalidInstrumentFacetReturns400(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=wind&instrument=not-a-real-instrument', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testInvalidYearFacetReturns400(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=wind&year=not-a-year', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testInvalidMinAmountFacetReturns400(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=wind&minAmount=-5', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testInvalidSectorFacetReturns400(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/search?q=wind&sector=not-a-number', server: $auth);

        self::assertResponseStatusCodeSame(400);
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
