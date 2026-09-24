<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/public/* (structuration des accès, 2026-09-24) : la seule
 * surface volontairement PUBLIC_ACCESS de toute l'API désormais. Vérifie
 * spécifiquement qu'aucune donnée sensible ne fuite par ce chemin -
 * l'objectif même de cette couche.
 */
final class PublicControllerTest extends WebTestCase
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

    public function testStatisticsIsAccessibleWithoutAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/public/statistics');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('countriesCovered', $data);
        self::assertArrayHasKey('sectorsTracked', $data);
        self::assertArrayHasKey('fundingRecords', $data);
        self::assertArrayHasKey('activeSources', $data);
    }

    public function testInstitutionsPreviewIsAccessibleWithoutAuthenticationAndNeverExposesAmounts(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);

        $source = new Source('World Bank Data API', SourceType::OfficialApi, SourceReliability::High);
        $this->entityManager->persist($source);
        $this->entityManager->flush();

        $client->request('GET', '/api/public/institutions');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertNotEmpty($data);

        $names = array_column($data, 'name');
        self::assertContains('World Bank Data API', $names);

        // Volontairement dépouillé : ni portefeuille, ni montant, ni
        // répartition - seulement de quoi identifier l'institution (voir la
        // docblock de PublicController::institutions()).
        foreach ($data as $institution) {
            self::assertSame(['id', 'name', 'type', 'reliability'], array_keys($institution));
        }
    }
}
