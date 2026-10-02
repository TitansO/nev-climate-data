<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReturnsOkStatus(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","service":"NEV Climate Data API"}',
            $client->getResponse()->getContent()
        );
    }

    /**
     * Audit post-C3 2026-10-02 (P-08) : regression test - ce endpoint
     * renvoyait auparavant 200 sans la moindre vérification, y compris
     * pendant une panne réelle de base de données (confirmé en direct
     * pendant l'audit via `docker stop` sur le conteneur de base de
     * données). La connexion est remplacée par un stub qui échoue
     * systématiquement, sans toucher à une vraie base.
     */
    public function testHealthEndpointReturns503WhenTheDatabaseIsUnreachable(): void
    {
        $client = static::createClient();

        $brokenConnection = $this->createStub(Connection::class);
        $brokenConnection->method('executeQuery')->willThrowException(
            new \RuntimeException('Connection refused (stub, no real database touched).'),
        );
        static::getContainer()->set(Connection::class, $brokenConnection);

        $client->request('GET', '/api/health');

        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"degraded","service":"NEV Climate Data API","database":"unreachable"}',
            $client->getResponse()->getContent()
        );
    }
}
