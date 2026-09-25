<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Opportunity;
use App\Entity\Project;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/opportunities (C1.8). Dataset seeded by seedDataset(), dates
 * relative to "now" (never hardcoded) so this test never rots into a
 * false pass/fail as real time passes:
 *   - "Closed Grant" (Renewable Energy): closed 10 days ago.
 *   - "Open Grant" (Agriculture): opened 10 days ago, closes in 10 days.
 *   - "Upcoming Grant" (no sector): opens in 10 days.
 * All three mention "Senegal" in their eligibility text except "Open
 * Grant", which mentions "Kenya" instead - lets the country search be
 * tested for real narrowing, not just presence.
 */
final class OpportunityControllerTest extends WebTestCase
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

        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        $source = new Source('Test Fund', SourceType::OfficialApi, SourceReliability::High);
        // Structuration des accès (2026-09-24) : GET /api/opportunities est
        // désormais ROLE_USER (auparavant PUBLIC_ACCESS) - seedDataset()
        // crée et connecte un utilisateur pour tous les tests de la liste,
        // en plus des données déjà nécessaires. Les tests /candidates plus
        // bas créaient déjà leur propre utilisateur séparément (déjà
        // protégé avant cette structuration) - inchangés.
        $listUser = new User('Amina Diallo', 'opportunities-list@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);

        foreach ([$renewableEnergy, $agriculture, $source, $listUser] as $entity) {
            $this->entityManager->persist($entity);
        }

        $now = new \DateTimeImmutable();

        $closed = new Opportunity('Closed Grant', $source, 'Pays éligibles : Senegal uniquement.', '1000000.00');
        $closed->setSector($renewableEnergy);
        $closed->setApplicationWindowOpensAt($now->modify('-40 days'));
        $closed->setApplicationWindowClosesAt($now->modify('-10 days'));
        $this->entityManager->persist($closed);

        $open = new Opportunity('Open Grant', $source, 'Pays éligibles : Kenya uniquement.', '2000000.00');
        $open->setSector($agriculture);
        $open->setApplicationWindowOpensAt($now->modify('-10 days'));
        $open->setApplicationWindowClosesAt($now->modify('+10 days'));
        // A3.12 : seule cette opportunité porte les champs d'enrichissement
        // renseignés - les deux autres les laissent à null (comportement
        // par défaut, testé séparément) pour vérifier les deux cas.
        $open->setInstrument(FundingInstrument::Guarantee);
        $open->setApplicationUrl('https://example.org/open-grant/apply');
        $open->setContactEmail('grants@example.org');
        $open->setRequiredDocuments('Note conceptuelle, budget prévisionnel.');
        $this->entityManager->persist($open);

        $upcoming = new Opportunity('Upcoming Grant', $source, 'Pays éligibles : Sénégal et pays voisins.', '3000000.00');
        $upcoming->setApplicationWindowOpensAt($now->modify('+10 days'));
        $upcoming->setApplicationWindowClosesAt($now->modify('+40 days'));
        $this->entityManager->persist($upcoming);

        $this->entityManager->flush();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'opportunities-list@example.com', 'password' => 'correct-horse-battery-staple']));
        $token = json_decode($client->getResponse()->getContent(), true)['token'];

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    public function testOpportunitiesRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testOpportunitiesSucceedsForAnAuthenticatedUser(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities', server: $auth);

        self::assertResponseIsSuccessful();
    }

    public function testOpportunitiesComputesStatusRelativeToNow(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        $byTitle = array_column($data, null, 'title');
        self::assertSame('closed', $byTitle['Closed Grant']['status']);
        self::assertSame('open', $byTitle['Open Grant']['status']);
        self::assertSame('upcoming', $byTitle['Upcoming Grant']['status']);
    }

    public function testOpportunitiesAreOrderedByClosingDateAscending(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities', server: $auth);

        $titles = array_column(json_decode($client->getResponse()->getContent(), true)['data'], 'title');
        self::assertSame(['Closed Grant', 'Open Grant', 'Upcoming Grant'], $titles);
    }

    public function testOpportunitiesFiltersBySector(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);

        $client->request('GET', '/api/opportunities?sector='.$agriculture->getId(), server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Grant', $data[0]['title']);
    }

    /**
     * A sectorless opportunity ("Upcoming Grant", multi-sector like
     * OpportunityFixtures's own "Blended Finance Window") must never
     * match a sector filter - it isn't "any sector", it has none set.
     */
    public function testOpportunitiesSectorFilterExcludesSectorlessRows(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);

        $client->request('GET', '/api/opportunities?sector='.$renewableEnergy->getId(), server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Closed Grant', $data[0]['title']);
    }

    public function testOpportunitiesFiltersByCountryTextSearch(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?country=kenya', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Grant', $data[0]['title']);
    }

    public function testOpportunitiesCountryFilterIsAccentInsensitive(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        // Data has the accent ("Sénégal"), the query doesn't.
        $client->request('GET', '/api/opportunities?'.http_build_query(['country' => 'senegal']), server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        $titles = array_column($data, 'title');
        sort($titles);
        self::assertSame(['Closed Grant', 'Upcoming Grant'], $titles);
    }

    public function testOpportunitiesFiltersByStatus(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?status=open', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Grant', $data[0]['title']);
    }

    public function testOpportunitiesFiltersByInstrument(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?instrument=guarantee', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Grant', $data[0]['title']);
    }

    public function testOpportunitiesRejectsAnInvalidInstrumentValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?instrument=not-a-real-instrument', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testOpportunitiesCombinesSectorAndStatusFilters(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);

        $client->request('GET', '/api/opportunities?sector='.$renewableEnergy->getId().'&status=open', server: $auth);

        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
    }

    public function testOpportunitiesRejectsAnInvalidStatusValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?status=not-a-real-status', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testOpportunitiesRejectsAnInvalidSectorValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?sector=not-a-number', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testOpportunitiesResultShapeIncludesSectorlessAsNull(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?status=upcoming', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertNull($data[0]['sector']);
        self::assertSame('Test Fund', $data[0]['sourceName']);
        self::assertEquals(3000000.0, $data[0]['envelopeAmount']);
        self::assertNull($data[0]['instrument']);
        self::assertNull($data[0]['applicationUrl']);
        self::assertNull($data[0]['contactEmail']);
        self::assertNull($data[0]['requiredDocuments']);
    }

    /**
     * A3.12 : les 4 champs d'enrichissement sortent bien tels que saisis
     * quand ils sont renseignés ("Open Grant" dans seedDataset()), et
     * l'instrument sort par sa valeur string (pas l'objet enum PHP).
     */
    public function testOpportunitiesResultShapeIncludesEnrichmentFieldsWhenSet(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?status=open', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('guarantee', $data[0]['instrument']);
        self::assertSame('https://example.org/open-grant/apply', $data[0]['applicationUrl']);
        self::assertSame('grants@example.org', $data[0]['contactEmail']);
        self::assertSame('Note conceptuelle, budget prévisionnel.', $data[0]['requiredDocuments']);
    }

    public function testOpportunitiesWithNoMatchesReturnsEmptyArrayNotAnError(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?country=zzzznomatchzzzz', server: $auth);

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
    }

    public function testOpportunitiesIsDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/opportunities', $spec['paths']);
        self::assertArrayHasKey('get', $spec['paths']['/api/opportunities']);
    }

    /**
     * C2.2 (sens inverse) : GET /api/opportunities/{id}/candidates - donnée
     * de pipeline interne (App\Entity\Project), contrairement à GET
     * /api/opportunities lui-même : requiert une authentification.
     */
    public function testCandidatesWithoutAuthenticationFails(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $opportunityId = $this->entityManager->getRepository(Opportunity::class)->findOneBy(['title' => 'Open Grant'])->getId();

        $client->request('GET', '/api/opportunities/'.$opportunityId.'/candidates');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCandidatesReturns404ForAnUnknownOpportunity(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $user = new User('Amina Diallo', 'candidates-404@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'candidates-404@example.com', 'password' => 'correct-horse-battery-staple']));
        $tokens = json_decode($client->getResponse()->getContent(), true);

        $client->request('GET', '/api/opportunities/999999/candidates', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCandidatesRanksProjectsBySectorAndCountryMatch(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($kenya);

        // "Open Grant" (Agriculture, eligible to Kenya only, seeded above).
        $goodMatch = new Project('Kenyan Agroforestry', $kenya, $agriculture, '500000.00', 'Test project.', 'Test Promoter');
        $poorMatch = new Project('Senegalese Solar Farm', $senegal, $agriculture, '500000.00', 'Test project.', 'Test Promoter');
        $this->entityManager->persist($goodMatch);
        $this->entityManager->persist($poorMatch);

        $user = new User('Amina Diallo', 'candidates-rank@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'candidates-rank@example.com', 'password' => 'correct-horse-battery-staple']));
        $tokens = json_decode($client->getResponse()->getContent(), true);

        $openGrantId = $this->entityManager->getRepository(Opportunity::class)->findOneBy(['title' => 'Open Grant'])->getId();
        $client->request('GET', '/api/opportunities/'.$openGrantId.'/candidates', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertSame('Kenyan Agroforestry', $data[0]['projectTitle']);
        self::assertGreaterThan($data[1]['score'], $data[0]['score']);
    }
}
