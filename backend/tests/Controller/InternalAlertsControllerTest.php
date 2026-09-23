<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * C3.8 : POST /api/internal/alerts/evaluate - authentification par jeton
 * partagé (X-Internal-Token), pas par JWT/clé API (voir App\Controller\
 * InternalAlertsController). Le jeton réel est lu depuis INTERNAL_TRIGGER_TOKEN
 * (config/services.yaml, app.internal_trigger_token) ; en environnement de
 * test, cette variable peut être absente ou vide - le contrôleur refuse
 * alors systématiquement (chaîne vide jamais acceptée comme jeton valide),
 * ce que ce test vérifie explicitement plutôt que de dépendre d'une valeur
 * particulière.
 */
final class InternalAlertsControllerTest extends WebTestCase
{
    public function testEvaluateRejectsAMissingToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/internal/alerts/evaluate');

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testEvaluateRejectsAWrongToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/internal/alerts/evaluate', server: ['HTTP_X_INTERNAL_TOKEN' => 'definitely-not-the-real-token']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testEvaluateIsDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/internal/alerts/evaluate', $spec['paths']);
    }
}
