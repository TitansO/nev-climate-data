<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\RecommendationService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C3.7 : "Recommendation Engine" - GET /api/recommendations. Lecture seule,
 * pur calcul dérivé de scores déjà publics (C2.6/C2.8/C3.1/C3.3). Audit
 * 2026-10-01 : ce docblock affirmait PUBLIC_ACCESS, resté faux depuis la
 * structuration des accès du 2026-09-24 - security.yaml déclare
 * explicitement `^/api/recommendations` en ROLE_USER.
 */
final class RecommendationController extends AbstractController
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
    ) {
    }

    #[Route('/api/recommendations', name: 'api_recommendations', methods: ['GET'])]
    #[OA\Get(
        summary: 'Classement priorisé des pays où allouer le prochain financement',
        description: 'Agrégation transparente (App\\Service\\RecommendationService), pas d\'apprentissage : moyenne pondérée à parts égales de l\'écart de couverture CDN (C3.1), l\'écart d\'équité (C3.3), l\'écart d\'alignement CDN (C2.6) et la sécurité d\'investissement (C2.8), sur les seuls signaux réellement disponibles par pays. Limité aux pays disposant d\'au moins un signal réel (le "pool équité", ~12/54 pays NEV).',
        tags: ['Recommendations'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Classement, du plus prioritaire au moins prioritaire',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'country', type: 'string'),
                        new OA\Property(property: 'countryIsoCode', type: 'string'),
                        new OA\Property(property: 'priorityScore', type: 'number', format: 'float', example: 0.62),
                        new OA\Property(property: 'signals', type: 'object', properties: [
                            new OA\Property(property: 'needGap', type: 'number', nullable: true),
                            new OA\Property(property: 'equityGap', type: 'number', nullable: true),
                            new OA\Property(property: 'ndcGap', type: 'number', nullable: true),
                            new OA\Property(property: 'investmentSafety', type: 'number'),
                        ]),
                        new OA\Property(property: 'justification', type: 'string'),
                    ]
                ))
            ),
        ]
    )]
    public function list(): JsonResponse
    {
        return $this->json($this->recommendationService->getRecommendations());
    }
}
