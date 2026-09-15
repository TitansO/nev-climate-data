<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Opportunity;
use App\Entity\Sector;
use App\Entity\Source;
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

    private function seedDataset(KernelBrowser $client): void
    {
        $client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        $source = new Source('Test Fund', SourceType::OfficialApi, SourceReliability::High);

        foreach ([$renewableEnergy, $agriculture, $source] as $entity) {
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
        $this->entityManager->persist($open);

        $upcoming = new Opportunity('Upcoming Grant', $source, 'Pays éligibles : Sénégal et pays voisins.', '3000000.00');
        $upcoming->setApplicationWindowOpensAt($now->modify('+10 days'));
        $upcoming->setApplicationWindowClosesAt($now->modify('+40 days'));
        $this->entityManager->persist($upcoming);

        $this->entityManager->flush();
    }

    public function testOpportunitiesIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities');

        self::assertResponseIsSuccessful();
    }

    public function testOpportunitiesComputesStatusRelativeToNow(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities');

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        $byTitle = array_column($data, null, 'title');
        self::assertSame('closed', $byTitle['Closed Grant']['status']);
        self::assertSame('open', $byTitle['Open Grant']['status']);
        self::assertSame('upcoming', $byTitle['Upcoming Grant']['status']);
    }

    public function testOpportunitiesAreOrderedByClosingDateAscending(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities');

        $titles = array_column(json_decode($client->getResponse()->getContent(), true)['data'], 'title');
        self::assertSame(['Closed Grant', 'Open Grant', 'Upcoming Grant'], $titles);
    }

    public function testOpportunitiesFiltersBySector(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);

        $client->request('GET', '/api/opportunities?sector='.$agriculture->getId());

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
        $this->seedDataset($client);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);

        $client->request('GET', '/api/opportunities?sector='.$renewableEnergy->getId());

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Closed Grant', $data[0]['title']);
    }

    public function testOpportunitiesFiltersByCountryTextSearch(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?country=kenya');

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Grant', $data[0]['title']);
    }

    public function testOpportunitiesCountryFilterIsAccentInsensitive(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        // Data has the accent ("Sénégal"), the query doesn't.
        $client->request('GET', '/api/opportunities?'.http_build_query(['country' => 'senegal']));

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        $titles = array_column($data, 'title');
        sort($titles);
        self::assertSame(['Closed Grant', 'Upcoming Grant'], $titles);
    }

    public function testOpportunitiesFiltersByStatus(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?status=open');

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame('Open Grant', $data[0]['title']);
    }

    public function testOpportunitiesCombinesSectorAndStatusFilters(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);

        $client->request('GET', '/api/opportunities?sector='.$renewableEnergy->getId().'&status=open');

        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
    }

    public function testOpportunitiesRejectsAnInvalidStatusValue(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?status=not-a-real-status');

        self::assertResponseStatusCodeSame(400);
    }

    public function testOpportunitiesRejectsAnInvalidSectorValue(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?sector=not-a-number');

        self::assertResponseStatusCodeSame(400);
    }

    public function testOpportunitiesResultShapeIncludesSectorlessAsNull(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?status=upcoming');

        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertNull($data[0]['sector']);
        self::assertSame('Test Fund', $data[0]['sourceName']);
        self::assertEquals(3000000.0, $data[0]['envelopeAmount']);
    }

    public function testOpportunitiesWithNoMatchesReturnsEmptyArrayNotAnError(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/opportunities?country=zzzznomatchzzzz');

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
}
