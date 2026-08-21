<?php

declare(strict_types=1);

namespace App\Controller;

use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    #[OA\Get(
        summary: 'Vérifie la disponibilité de l\'API',
        tags: ['Health'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'L\'API est opérationnelle',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'service', type: 'string', example: 'NEV Climate Data API'),
                    ]
                )
            ),
        ]
    )]
    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'service' => 'NEV Climate Data API',
        ]);
    }
}
