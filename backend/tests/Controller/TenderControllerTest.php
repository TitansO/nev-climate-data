<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\TenderCategory;
use App\Entity\Enum\UserRole;
use App\Entity\Sector;
use App\Entity\Tender;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/tenders (A3.13). Même structure que OpportunityControllerTest
 * (C1.8) - dates relatives à "maintenant" (jamais codées en dur) pour ne
 * jamais pourrir en faux positif/négatif avec le temps qui passe :
 *   - "Closed Tender" (Renewable Energy) : échéance il y a 10 jours.
 *   - "Open Tender" (Agriculture, goods) : publié il y a 10 jours, échéance dans 10 jours.
 *   - "Upcoming Tender" (sans secteur) : publié dans 10 jours.
 */
final class TenderControllerTest extends WebTestCase
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
        $listUser = new User('Amina Diallo', 'tenders-list@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);

        foreach ([$renewableEnergy, $agriculture, $listUser] as $entity) {
            $this->entityManager->persist($entity);
        }

        $now = new \DateTimeImmutable();

        $closed = new Tender('Closed Tender', 'Test Org', TenderCategory::Consulting, 'Description.', 'Critères.', $now->modify('-40 days'), $now->modify('-10 days'));
        $closed->setSector($renewableEnergy);
        $this->entityManager->persist($closed);

        $open = new Tender('Open Tender', 'Test Org', TenderCategory::Goods, 'Description.', 'Critères.', $now->modify('-10 days'), $now->modify('+10 days'));
        $open->setSector($agriculture);
        $open->setReferenceNumber('TEST-2026-001');
        $open->setEstimatedValue('450000.00');
        $open->setDocumentsUrl('https://example.org/tender/apply');
        $open->setContactEmail('procurement@example.org');
        $this->entityManager->persist($open);

        $upcoming = new Tender('Upcoming Tender', 'Test Org', TenderCategory::Works, 'Description.', 'Critères.', $now->modify('+10 days'), $now->modify('+40 days'));
        $this->entityManager->persist($upcoming);

        $this->entityManager->flush();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'tenders-list@example.com', 'password' => 'correct-horse-battery-staple']));
        $token = json_decode($client->getResponse()->getContent(), true)['token'];

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    public function testTendersRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/tenders');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTendersSucceedsForAnAuthenticatedUser(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders', server: $auth);

        self::assertResponseIsSuccessful();
        self::assertCount(3, json_decode($client->getResponse()->getContent(), true)['data']);
    }

    public function testTendersComputesStatusRelativeToNow(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders', server: $auth);

        $byTitle = [];
        foreach (json_decode($client->getResponse()->getContent(), true)['data'] as $row) {
            $byTitle[$row['title']] = $row['status'];
        }

        self::assertSame('closed', $byTitle['Closed Tender']);
        self::assertSame('open', $byTitle['Open Tender']);
        self::assertSame('upcoming', $byTitle['Upcoming Tender']);
    }

    public function testTendersAreOrderedBySubmissionDeadlineAscending(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders', server: $auth);

        $titles = array_column(json_decode($client->getResponse()->getContent(), true)['data'], 'title');
        self::assertSame(['Closed Tender', 'Open Tender', 'Upcoming Tender'], $titles);
    }

    public function testTendersFiltersBySector(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);
        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);

        $client->request('GET', '/api/tenders?sector='.$agriculture->getId(), server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Tender', $data[0]['title']);
    }

    public function testTendersFiltersByCategory(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders?category=goods', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Tender', $data[0]['title']);
    }

    public function testTendersRejectsAnInvalidCategoryValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders?category=not-a-real-category', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testTendersFiltersByStatus(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders?status=open', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Tender', $data[0]['title']);
    }

    public function testTendersRejectsAnInvalidStatusValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders?status=not-a-real-status', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testTendersFiltersBySearchTermInTitleOrIssuingOrganization(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders?search=open', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Tender', $data[0]['title']);
    }

    public function testTendersRejectsAnInvalidSectorValue(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders?sector=not-a-number', server: $auth);

        self::assertResponseStatusCodeSame(400);
    }

    public function testTendersResultShapeIncludesOptionalFieldsAsNullWhenUnset(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders?status=upcoming', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertNull($data[0]['sector']);
        self::assertNull($data[0]['referenceNumber']);
        self::assertNull($data[0]['estimatedValue']);
        self::assertNull($data[0]['documentsUrl']);
        self::assertNull($data[0]['contactEmail']);
    }

    public function testTendersResultShapeIncludesFieldsWhenSet(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders?status=open', server: $auth);

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Agriculture', $data[0]['sector']);
        self::assertSame('goods', $data[0]['category']);
        self::assertSame('TEST-2026-001', $data[0]['referenceNumber']);
        self::assertEquals(450000.0, $data[0]['estimatedValue']);
        self::assertSame('https://example.org/tender/apply', $data[0]['documentsUrl']);
        self::assertSame('procurement@example.org', $data[0]['contactEmail']);
    }

    public function testTendersWithNoMatchesReturnsEmptyArrayNotAnError(): void
    {
        $client = static::createClient();
        $auth = $this->seedDataset($client);

        $client->request('GET', '/api/tenders?search=zzzznomatchzzzz', server: $auth);

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
    }

    public function testTendersIsDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/tenders', $spec['paths']);
        self::assertArrayHasKey('get', $spec['paths']['/api/tenders']);
    }
}
