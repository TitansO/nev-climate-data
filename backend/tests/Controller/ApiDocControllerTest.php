<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiDocControllerTest extends WebTestCase
{
    public function testOpenApiJsonSpecIsAvailable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();

        $spec = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('openapi', $spec);
        self::assertArrayHasKey('/api/health', $spec['paths']);
    }

    public function testSwaggerUiIsAvailable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc');

        self::assertResponseIsSuccessful();
    }
}
