<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\NdcTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/countries/{iso}/scores/{ndc-alignment,investment-risk,equity}
 * (C2.6/C2.8/C3.3) - premier test de contrôleur jamais écrit pour ces 3
 * endpoints publics, malgré leur utilisation en production (audit du
 * 2026-09-23).
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

    public function testNdcAlignmentReturns404ForAnUnknownIsoCode(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/countries/ZZZ/scores/ndc-alignment');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testNdcAlignmentIsUnavailableWithoutAnyNdcTarget(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->entityManager->persist(new Country('Botswana', 'BWA', "Afrique australe"));
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/BWA/scores/ndc-alignment');

        self::assertResponseIsSuccessful();
        self::assertSame(['available' => false], json_decode($client->getResponse()->getContent(), true));
    }

    public function testNdcAlignmentIsAvailableWithARealNdcTarget(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $country = new Country('Ethiopia', 'ETH', "Afrique de l'Est");
        $this->entityManager->persist($country);
        $target = new NdcTarget($country, 2030);
        $target->setConditional(true);
        $this->entityManager->persist($target);
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/ETH/scores/ndc-alignment');

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

        $client->request('GET', '/api/countries/ZZZ/scores/investment-risk');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testInvestmentRiskIsNeverUnavailableEvenWithoutAnyRealData(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->entityManager->persist(new Country('Lesotho', 'LSO', 'Afrique australe'));
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/LSO/scores/investment-risk');

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

        $client->request('GET', '/api/countries/ZZZ/scores/equity');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testEquityIsUnavailableWithoutPopulationOrVulnerabilityIndex(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->entityManager->persist(new Country('Namibia', 'NAM', 'Afrique australe'));
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/NAM/scores/equity');

        self::assertResponseIsSuccessful();
        self::assertSame(['available' => false], json_decode($client->getResponse()->getContent(), true));
    }

    public function testEquityIsAvailableWithPopulationAndVulnerabilityIndexSet(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $country = new Country('Malawi', 'MWI', 'Afrique australe');
        $country->setPopulation(20_000_000);
        $country->setVulnerabilityIndex(0.7);
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        $client->request('GET', '/api/countries/MWI/scores/equity');

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
