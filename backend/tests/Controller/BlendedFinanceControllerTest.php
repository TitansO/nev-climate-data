<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Sector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BlendedFinanceControllerTest extends WebTestCase
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

    private function seedSector(KernelBrowser $client): Sector
    {
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $sector = new Sector('Renewable Energy');
        $this->entityManager->persist($sector);
        $this->entityManager->flush();

        return $sector;
    }

    public function testSimulateIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $sector = $this->seedSector($client);

        $client->request('POST', '/api/blended/simulate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sector' => $sector->getId(), 'publicAmount' => 1000000]));

        self::assertResponseIsSuccessful();
    }

    public function testSimulateReturnsTheExpectedWaterfallShape(): void
    {
        $client = static::createClient();
        $sector = $this->seedSector($client);

        $client->request('POST', '/api/blended/simulate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'sector' => $sector->getId(),
            'publicAmount' => 1000000,
            'instrumentMix' => ['guarantee' => 0.7, 'grant' => 0.3],
        ]));

        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Renewable Energy', $body['sector']);
        self::assertSame(4.06, $body['leverageRatio']);
        self::assertCount(3, $body['waterfall']);
    }

    public function testSimulateRejectsAnUnknownSector(): void
    {
        $client = static::createClient();
        $this->seedSector($client);

        $client->request('POST', '/api/blended/simulate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sector' => 999999, 'publicAmount' => 1000]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSimulateRejectsAMissingPublicAmount(): void
    {
        $client = static::createClient();
        $sector = $this->seedSector($client);

        $client->request('POST', '/api/blended/simulate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sector' => $sector->getId()]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSimulateRejectsAnInstrumentMixThatDoesNotSumToOne(): void
    {
        $client = static::createClient();
        $sector = $this->seedSector($client);

        $client->request('POST', '/api/blended/simulate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'sector' => $sector->getId(),
            'publicAmount' => 1000,
            'instrumentMix' => ['guarantee' => 0.5, 'grant' => 0.2],
        ]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testBlendedFinanceEndpointIsDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/blended/simulate', $spec['paths']);
    }
}
