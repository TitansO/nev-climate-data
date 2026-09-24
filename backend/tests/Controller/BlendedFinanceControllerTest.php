<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\Sector;
use App\Entity\User;
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

    /**
     * Structuration des accès (2026-09-24) : /api/blended est désormais
     * ROLE_USER - seed un utilisateur et son secteur dans la même
     * transaction (voir ProjectControllerTest, même raisonnement sur
     * disableReboot()), puis retourne le header Authorization prêt à
     * l'emploi avec le Sector créé, pour ne pas dupliquer 2 fois la même
     * mise en place dans chaque test.
     *
     * @return array{0: Sector, 1: array{HTTP_AUTHORIZATION: string}}
     */
    private function seedSectorAndAuth(KernelBrowser $client): array
    {
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $sector = new Sector('Renewable Energy');
        $user = new User('Amina Diallo', 'amina@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'amina@example.com', 'password' => 'correct-horse-battery-staple']));
        $token = json_decode($client->getResponse()->getContent(), true)['token'];

        return [$sector, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]];
    }

    public function testSimulateRequiresAuthentication(): void
    {
        $client = static::createClient();
        [$sector] = $this->seedSectorAndAuth($client);

        $client->request('POST', '/api/blended/simulate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sector' => $sector->getId(), 'publicAmount' => 1000000]));

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testSimulateSucceedsForAnAuthenticatedUser(): void
    {
        $client = static::createClient();
        [$sector, $auth] = $this->seedSectorAndAuth($client);

        $client->request('POST', '/api/blended/simulate', server: $auth + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sector' => $sector->getId(), 'publicAmount' => 1000000]));

        self::assertResponseIsSuccessful();
    }

    public function testSimulateReturnsTheExpectedWaterfallShape(): void
    {
        $client = static::createClient();
        [$sector, $auth] = $this->seedSectorAndAuth($client);

        $client->request('POST', '/api/blended/simulate', server: $auth + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
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
        [, $auth] = $this->seedSectorAndAuth($client);

        $client->request('POST', '/api/blended/simulate', server: $auth + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sector' => 999999, 'publicAmount' => 1000]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSimulateRejectsAMissingPublicAmount(): void
    {
        $client = static::createClient();
        [$sector, $auth] = $this->seedSectorAndAuth($client);

        $client->request('POST', '/api/blended/simulate', server: $auth + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sector' => $sector->getId()]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSimulateRejectsAnInstrumentMixThatDoesNotSumToOne(): void
    {
        $client = static::createClient();
        [$sector, $auth] = $this->seedSectorAndAuth($client);

        $client->request('POST', '/api/blended/simulate', server: $auth + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
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
