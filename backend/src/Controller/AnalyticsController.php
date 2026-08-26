<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AnalyticsService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-side aggregates for the 3 analytics charts on the frontend's
 * visualizations page (A2.5). Deliberately PUBLIC_ACCESS, like
 * GET /api/funding (see security.yaml): visualizations.html itself has no
 * login requirement, so there is no reason for the data feeding its charts
 * to require one either. No query parameters - the page has no filter UI
 * to drive them (see the A2.5/A2.6 implementation report). Every response
 * is served through a 15-minute Redis cache (App\Service\AnalyticsService).
 */
final class AnalyticsController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
    ) {
    }

    #[Route('/api/analytics/financing-trends', name: 'api_analytics_financing_trends', methods: ['GET'])]
    #[OA\Get(
        summary: 'Tendances de financement par année et type (mis en cache 15 min)',
        description: 'Montants agrégés (SUM) par année, ventilés par type de financement (public/private/multilateral). Ordre chronologique stable. Réponse servie depuis un cache Redis dédié, TTL 900 secondes.',
        tags: ['Analytics'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Série chronologique des montants agrégés',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'period', type: 'integer', example: 2025),
                                new OA\Property(property: 'public', type: 'number', format: 'float', example: 1200000),
                                new OA\Property(property: 'private', type: 'number', format: 'float', example: 800000),
                                new OA\Property(property: 'multilateral', type: 'number', format: 'float', example: 950000),
                                new OA\Property(property: 'total', type: 'number', format: 'float', example: 2950000),
                            ]
                        )),
                    ]
                )
            ),
        ]
    )]
    public function financingTrends(): JsonResponse
    {
        return $this->json(['data' => $this->analyticsService->getFinancingTrends()]);
    }

    #[Route('/api/analytics/sector-distribution', name: 'api_analytics_sector_distribution', methods: ['GET'])]
    #[OA\Get(
        summary: 'Répartition des financements par secteur (mis en cache 15 min)',
        description: 'Montants agrégés (SUM) par secteur réel, triés du plus grand au plus petit (ordre déterministe : égalité départagée par id de secteur). Le pourcentage est calculé côté serveur sur le total agrégé. Réponse servie depuis un cache Redis dédié, TTL 900 secondes.',
        tags: ['Analytics'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Répartition sectorielle',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'sector', type: 'string', example: 'Renewable Energy'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 2500000),
                                new OA\Property(property: 'percentage', type: 'number', format: 'float', example: 32.5),
                            ]
                        )),
                    ]
                )
            ),
        ]
    )]
    public function sectorDistribution(): JsonResponse
    {
        return $this->json(['data' => $this->analyticsService->getSectorDistribution()]);
    }

    #[Route('/api/analytics/co2-reduction', name: 'api_analytics_co2_reduction', methods: ['GET'])]
    #[OA\Get(
        summary: 'Réduction CO2 estimée (mis en cache 15 min)',
        description: 'Aucune donnée d\'émissions ni facteur de conversion officiel n\'existe dans le schéma actuel (voir le rapport d\'implémentation A2.5/A2.6) - cet endpoint retourne donc systématiquement `available: false` plutôt que d\'inventer un chiffre. Structure conservée pour rester compatible avec un futur calcul réel (Volet B). Réponse servie depuis un cache Redis dédié, TTL 900 secondes.',
        tags: ['Analytics'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Indisponibilité explicite de la donnée',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'available', type: 'boolean', example: false),
                        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                        new OA\Property(property: 'reason', type: 'string', example: 'Aucune donnée d\'émissions ni facteur de conversion CO2 n\'existe dans le schéma actuel.'),
                    ]
                )
            ),
        ]
    )]
    public function co2Reduction(): JsonResponse
    {
        return $this->json($this->analyticsService->getCo2Reduction());
    }
}
