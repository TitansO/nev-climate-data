<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Audit post-C3 2026-10-01 : GET /api/recommendations (C3.7) n'avait aucun
 * test HTTP - seule la logique de RecommendationService l'etait deja
 * (RecommendationServiceTest). Couvre ici le routage, l'authentification
 * et la forme JSON de reponse - propre a la couche controleur.
 */
final class RecommendationControllerTest extends WebTestCase
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

    public function testListRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/recommendations');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testListReturnsAnArrayForAnAuthenticatedUserWithNoData(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $user = new User('Amina Diallo', 'recommendations@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $container->get('cache.analytics')->clear();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'recommendations@example.com', 'password' => 'correct-horse-battery-staple']));
        $token = json_decode($client->getResponse()->getContent(), true)['token'];

        $client->request('GET', '/api/recommendations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        // Restreint au "pool equite" (pays avec population + indice de
        // vulnerabilite, voir CountryEquityDataFixtures) - aucun pays
        // seede ici, donc une liste vide, jamais une erreur.
        self::assertSame([], json_decode($client->getResponse()->getContent(), true));
    }

    public function testEndpointIsDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/recommendations', $spec['paths']);
        self::assertArrayHasKey('get', $spec['paths']['/api/recommendations']);
    }
}
