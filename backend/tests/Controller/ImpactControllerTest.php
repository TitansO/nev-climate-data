<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\ImpactFactor;
use App\Entity\Sector;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Audit post-C3 2026-10-01 : GET /api/sectors et POST /api/impact/estimate
 * (C2.5) n'avaient aucun test HTTP - seule la logique de
 * ImpactCalculatorService (voir ImpactCalculatorServiceTest) a jamais ete
 * couverte. Couvre ici le routage, l'authentification, et la validation
 * des parametres - propre a la couche controleur.
 */
final class ImpactControllerTest extends WebTestCase
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
    private function seedUserAndLogin(KernelBrowser $client): array
    {
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $user = new User('Amina Diallo', 'impact@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'impact@example.com', 'password' => 'correct-horse-battery-staple']));
        $token = json_decode($client->getResponse()->getContent(), true)['token'];

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    public function testSectorsListRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/sectors');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testSectorsListReturnsSectorsSortedByName(): void
    {
        $client = static::createClient();
        $auth = $this->seedUserAndLogin($client);

        $this->entityManager->persist(new Sector('Renewable Energy'));
        $this->entityManager->persist(new Sector('Agriculture'));
        $this->entityManager->flush();

        $client->request('GET', '/api/sectors', server: $auth);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertSame(['Agriculture', 'Renewable Energy'], array_column($data, 'name'));
        self::assertSame(['id', 'name'], array_keys($data[0]));
    }

    public function testEstimateRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/impact/estimate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sector' => 1, 'amountUsd' => 1000]));

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testEstimateReturnsAvailableFalseWhenNoFactorExistsForTheSector(): void
    {
        $client = static::createClient();
        $auth = $this->seedUserAndLogin($client);

        $sector = new Sector('Agriculture');
        $this->entityManager->persist($sector);
        $this->entityManager->flush();

        $client->request('POST', '/api/impact/estimate', server: ['CONTENT_TYPE' => 'application/json', ...$auth], content: json_encode(['sector' => $sector->getId(), 'amountUsd' => 1000]));

        self::assertResponseIsSuccessful();
        self::assertSame(['available' => false], json_decode($client->getResponse()->getContent(), true));
    }

    public function testEstimateReturnsTheComputedMetricsWhenAFactorExists(): void
    {
        $client = static::createClient();
        $auth = $this->seedUserAndLogin($client);

        $sector = new Sector('Renewable Energy');
        $this->entityManager->persist($sector);
        $factor = new ImpactFactor($sector, 20.0, 'IRENA Renewable Power Generation Costs 2023');
        $factor->setUsdPerMwInstalled('1000000.00');
        $this->entityManager->persist($factor);
        $this->entityManager->flush();

        $client->request('POST', '/api/impact/estimate', server: ['CONTENT_TYPE' => 'application/json', ...$auth], content: json_encode(['sector' => $sector->getId(), 'amountUsd' => 5_000_000]));

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($body['available']);
        self::assertSame('Renewable Energy', $body['sector']);
        // assertEquals, not assertSame: json_encode() drops the trailing
        // ".0" from a whole-number float (e.g. 5.0 -> "5"), so a
        // round-tripped whole value decodes back as a PHP int - same
        // gotcha already documented in AnalyticsControllerTest/ProjectControllerTest.
        self::assertEquals(['central' => 5.0, 'low' => 4.0, 'high' => 6.0], $body['estimates']['mwInstalled']);
    }

    public function testEstimateRejectsAMissingSector(): void
    {
        $client = static::createClient();
        $auth = $this->seedUserAndLogin($client);

        $client->request('POST', '/api/impact/estimate', server: ['CONTENT_TYPE' => 'application/json', ...$auth], content: json_encode(['amountUsd' => 1000]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testEstimateRejectsASectorThatDoesNotExist(): void
    {
        $client = static::createClient();
        $auth = $this->seedUserAndLogin($client);

        $client->request('POST', '/api/impact/estimate', server: ['CONTENT_TYPE' => 'application/json', ...$auth], content: json_encode(['sector' => 999999, 'amountUsd' => 1000]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testEstimateRejectsAZeroOrNegativeAmount(): void
    {
        $client = static::createClient();
        $auth = $this->seedUserAndLogin($client);

        $sector = new Sector('Agriculture');
        $this->entityManager->persist($sector);
        $this->entityManager->flush();

        $client->request('POST', '/api/impact/estimate', server: ['CONTENT_TYPE' => 'application/json', ...$auth], content: json_encode(['sector' => $sector->getId(), 'amountUsd' => 0]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testEstimateRejectsInvalidJson(): void
    {
        $client = static::createClient();
        $auth = $this->seedUserAndLogin($client);

        $client->request('POST', '/api/impact/estimate', server: ['CONTENT_TYPE' => 'application/json', ...$auth], content: 'not json');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }
}
