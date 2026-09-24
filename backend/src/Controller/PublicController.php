<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Source;
use App\Repository\SourceRepository;
use App\Service\AnalyticsService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Structuration des accès (2026-09-24) : la seule surface volontairement
 * PUBLIC_ACCESS (security.yaml) de toute l'API. Un visiteur sans compte
 * doit pouvoir découvrir la valeur de NEV, jamais reproduire le jeu de
 * données ni utiliser les fonctionnalités principales - chaque endpoint ici
 * ne retourne donc que des agrégats ou des champs non sensibles (jamais un
 * montant individuel, un détail de financement, ou une liste exhaustive
 * filtrable comme GET /api/institutions).
 */
final class PublicController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
        private readonly SourceRepository $sourceRepository,
    ) {
    }

    #[Route('/api/public/statistics', name: 'api_public_statistics', methods: ['GET'])]
    #[OA\Get(
        summary: 'Statistiques agrégées pour le Hero de la page d\'accueil (public, non authentifié)',
        description: 'Mêmes 4 compteurs agrégés que GET /api/analytics/hero-stats (réservé aux comptes authentifiés depuis la structuration des accès) - countriesCovered/activeSources comptent les pays/sources ayant au moins un enregistrement Funding, sectorsTracked le nombre total de secteurs suivis, fundingRecords le nombre total d\'enregistrements Funding. Aucune donnée individuelle. Réponse servie depuis le même cache Redis 15 minutes (App\\Service\\AnalyticsService).',
        tags: ['Public'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques publiques',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'countriesCovered', type: 'integer', example: 54),
                        new OA\Property(property: 'sectorsTracked', type: 'integer', example: 5),
                        new OA\Property(property: 'fundingRecords', type: 'integer', example: 1080),
                        new OA\Property(property: 'activeSources', type: 'integer', example: 4),
                    ]
                )
            ),
        ]
    )]
    public function statistics(): JsonResponse
    {
        return $this->json($this->analyticsService->getHeroStats());
    }

    #[Route('/api/public/institutions', name: 'api_public_institutions', methods: ['GET'])]
    #[OA\Get(
        summary: 'Aperçu public des institutions partenaires (nom, type, fiabilité - public, non authentifié)',
        description: 'Volontairement dépouillé par rapport à GET /api/institutions/{sourceId} (réservé aux comptes authentifiés) : ni portefeuille, ni répartition sectorielle/géographique, ni montants - seulement de quoi montrer "qui sont nos sources" sans reproduire les données de financement elles-mêmes.',
        tags: ['Public'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Institutions partenaires (aperçu)',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 12),
                            new OA\Property(property: 'name', type: 'string', example: 'World Bank Data API'),
                            new OA\Property(property: 'type', type: 'string', example: 'official_api'),
                            new OA\Property(property: 'reliability', type: 'string', enum: ['low', 'medium', 'high'], example: 'high'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function institutions(): JsonResponse
    {
        $sources = $this->sourceRepository->findBy([], ['name' => 'ASC']);

        return $this->json(array_map(
            static fn (Source $source) => [
                'id' => $source->getId(),
                'name' => $source->getName(),
                'type' => $source->getType()->value,
                'reliability' => $source->getReliability()->value,
            ],
            $sources
        ));
    }
}
