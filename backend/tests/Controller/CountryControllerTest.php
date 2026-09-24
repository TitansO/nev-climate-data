<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\Enum\UserRole;
use App\Entity\NdcTarget;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/countries/{iso}/scores/{ndc-alignment,investment-risk,equity}
 * (C2.6/C2.8/C3.3) - premier test de contrôleur jamais écrit pour ces 3
 * endpoints, malgré leur utilisation en production (audit du 2026-09-23).
 * Réservés à ROLE_USER depuis la structuration des accès du 2026-09-24
 * (auparavant PUBLIC_ACCESS).
 */
final class CountryControllerTest extends WebTestCase
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

    private function beginTransaction(KernelBrowser $client): void
    {
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeader(KernelBrowser $client): array
    {
        $user = new User('Amina Diallo', 'amina@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'amina@example.com', 'password' => 'correct-horse-battery-staple']));
        $token = json_decode($client->getResponse()->getContent(), true)['token'];

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    public function testScoreEndpointsRequireAuthentication(): void
    {
        $client = static::createClient();

        foreach (['ndc-alignment', 'investment-risk', 'equity'] as $scoreType) {
            $client->request('GET', '/api/countries/ZZZ/scores/'.$scoreType);
            self::assertSame(401, $client->getResponse()->getStatusCode(), $scoreType);
        }
    }

    public function testNdcAlignmentReturns404ForAnUnknownIsoCode(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $auth = $this->authHeader($client);

        $client->request('GET', '/api/countries/ZZZ/scores/ndc-alignment', server: $auth);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testNdcAlignmentIsUnavailableWithoutAnyNdcTarget(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $auth = $this->authHeader($client);
        $this->entityManager->persist(new Country('Botswana', 'BWA', "Afrique australe"));
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/BWA/scores/ndc-alignment', server: $auth);

        self::assertResponseIsSuccessful();
        self::assertSame(['available' => false], json_decode($client->getResponse()->getContent(), true));
    }

    public function testNdcAlignmentIsAvailableWithARealNdcTarget(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $auth = $this->authHeader($client);
        $country = new Country('Ethiopia', 'ETH', "Afrique de l'Est");
        $this->entityManager->persist($country);
        $target = new NdcTarget($country, 2030);
        $target->setConditional(true);
        $this->entityManager->persist($target);
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/ETH/scores/ndc-alignment', server: $auth);

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($body['available']);
        self::assertSame('ndc_alignment', $body['scoreType']);
        self::assertGreaterThanOrEqual(0.0, $body['value']);
        self::assertLessThanOrEqual(100.0, $body['value']);
    }

    public function testInvestmentRiskReturns404ForAnUnknownIsoCode(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $auth = $this->authHeader($client);

        $client->request('GET', '/api/countries/ZZZ/scores/investment-risk', server: $auth);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testInvestmentRiskIsNeverUnavailableEvenWithoutAnyRealData(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $auth = $this->authHeader($client);
        $this->entityManager->persist(new Country('Lesotho', 'LSO', 'Afrique australe'));
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/LSO/scores/investment-risk', server: $auth);

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        // Jamais "available:false" (contrairement a ndc-alignment/equity) :
        // un pays existe toujours independamment d'avoir des donnees reelles.
        self::assertArrayNotHasKey('available', $body);
        self::assertSame('investment_risk', $body['scoreType']);
        self::assertGreaterThanOrEqual(0.0, $body['value']);
        self::assertLessThanOrEqual(100.0, $body['value']);
    }

    public function testEquityReturns404ForAnUnknownIsoCode(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $auth = $this->authHeader($client);

        $client->request('GET', '/api/countries/ZZZ/scores/equity', server: $auth);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testEquityIsUnavailableWithoutPopulationOrVulnerabilityIndex(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $auth = $this->authHeader($client);
        $this->entityManager->persist(new Country('Namibia', 'NAM', 'Afrique australe'));
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/NAM/scores/equity', server: $auth);

        self::assertResponseIsSuccessful();
        self::assertSame(['available' => false], json_decode($client->getResponse()->getContent(), true));
    }

    public function testEquityIsAvailableWithPopulationAndVulnerabilityIndexSet(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $auth = $this->authHeader($client);
        $country = new Country('Malawi', 'MWI', 'Afrique australe');
        $country->setPopulation(20_000_000);
        $country->setVulnerabilityIndex(0.7);
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/MWI/scores/equity', server: $auth);

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($body['available']);
        self::assertSame('equity', $body['scoreType']);
    }

    public function testCountryScoreEndpointsAreDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/countries/{iso}/scores/ndc-alignment', $spec['paths']);
        self::assertArrayHasKey('/api/countries/{iso}/scores/investment-risk', $spec['paths']);
        self::assertArrayHasKey('/api/countries/{iso}/scores/equity', $spec['paths']);
    }
}
