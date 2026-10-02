<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Audit post-C3 2026-10-02 (P-01) : X-Internal-Token authentifie
 * ai/ingest.py auprès de ^/api/reports et ^/api/analytics (ROLE_USER) -
 * voir App\Security\InternalServiceAuthenticator. Même convention de test
 * que InternalAlertsControllerTest (C3.8) pour la même raison : en
 * environnement de test, INTERNAL_TRIGGER_TOKEN peut être absent ou vide -
 * ce test vérifie donc uniquement le rejet (jeton absent ou incorrect),
 * jamais le succès avec le jeton réel.
 */
final class InternalServiceAuthenticatorTest extends WebTestCase
{
    public function testGetReportsRejectsAMissingTokenAndNoJwt(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/reports');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testGetReportsRejectsAWrongInternalToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/reports', server: ['HTTP_X_INTERNAL_TOKEN' => 'definitely-not-the-real-token']);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAnalyticsHeroStatsRejectsAWrongInternalToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/analytics/hero-stats', server: ['HTTP_X_INTERNAL_TOKEN' => 'definitely-not-the-real-token']);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAWrongInternalTokenNeverInterferesWithNormalJwtAuthentication(): void
    {
        // Regression check: supports() must stay exclusive to requests
        // actually carrying X-Internal-Token - a request with neither
        // header nor JWT must still fail the same way it always did (401),
        // not be swallowed differently by the new authenticator.
        $client = static::createClient();

        $client->request('GET', '/api/funding');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /**
     * Regression test - une première version de supports() réclamait
     * toute requête portant X-Internal-Token, sans restriction de chemin.
     * POST /api/internal/alerts/evaluate (App\Controller\
     * InternalAlertsController) gère déjà entièrement son propre jeton et
     * répond 403 (AccessDeniedHttpException) sur un jeton invalide - cet
     * authenticator-ci ne doit jamais intercepter cette route avant que ce
     * contrôle indépendant ne s'exécute, sous peine de transformer son 403
     * en 401 (cassé puis corrigé pendant cet audit, voir
     * InternalServiceAuthenticator::SUPPORTED_PATH_PREFIXES).
     */
    public function testAWrongInternalTokenOnAnUnrelatedInternalRouteStaysOutOfItsWay(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/internal/alerts/evaluate', server: ['HTTP_X_INTERNAL_TOKEN' => 'definitely-not-the-real-token']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
