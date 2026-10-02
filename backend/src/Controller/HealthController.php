<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Audit post-C3 2026-10-02 (P-08) : renvoyait auparavant {"status":"ok"}
     * de façon inconditionnelle, sans la moindre vérification - confirmé en
     * direct pendant l'audit qu'un arrêt complet de la base de données
     * (docker stop) laissait ce endpoint répondre 200 pendant que
     * GET /api/funding et POST /api/auth/login échouaient en 500 au même
     * instant. Un orchestrateur (Render, un load balancer, un health check
     * Docker) qui ne surveille que ce endpoint n'aurait jamais détecté la
     * panne. Même motif que le propre health check du service `ai/`
     * (ai/main.py), qui vérifie déjà sa base pour exactement cette raison -
     * jamais appliqué ici avant cet audit. Une requête triviale suffit :
     * ce endpoint sert de liveness ET readiness probe, pas un simple "le
     * processus PHP répond".
     */
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    #[OA\Get(
        summary: 'Vérifie la disponibilité de l\'API et de la base de données',
        tags: ['Health'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'L\'API et la base de données sont opérationnelles',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'service', type: 'string', example: 'NEV Climate Data API'),
                    ]
                )
            ),
            new OA\Response(
                response: 503,
                description: 'La base de données est inaccessible',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'degraded'),
                        new OA\Property(property: 'service', type: 'string', example: 'NEV Climate Data API'),
                        new OA\Property(property: 'database', type: 'string', example: 'unreachable'),
                    ]
                )
            ),
        ]
    )]
    public function health(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return $this->json(
                ['status' => 'degraded', 'service' => 'NEV Climate Data API', 'database' => 'unreachable'],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $this->json([
            'status' => 'ok',
            'service' => 'NEV Climate Data API',
        ]);
    }
}
