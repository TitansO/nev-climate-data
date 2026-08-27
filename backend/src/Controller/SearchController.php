<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\SearchQuery;
use App\Service\SearchService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Global search (A2.8) across Country, Sector, Source and published Report
 * - see App\Service\SearchService's docblock for why the scope stops
 * there. Deliberately PUBLIC_ACCESS, like GET /api/funding and
 * GET /api/analytics/*: every searched entity is already public data
 * (Country/Sector/Source are exposed via GET /api/funding's responses;
 * Report search excludes Draft).
 */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {
    }

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    #[OA\Get(
        summary: 'Recherche globale (pays, secteurs, sources, rapports publiés)',
        description: 'Recherche insensible à la casse et aux accents, sur le nom/titre de chaque type. `q` est requis, entre 2 et 100 caractères (espaces superflus ignorés). Au plus 5 résultats par type, triés par ordre alphabétique au sein de chaque type. Les rapports en statut "draft" ne sont jamais retournés.',
        tags: ['Search'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: true, description: 'Terme recherché (2 à 100 caractères)', schema: new OA\Schema(type: 'string'), example: 'senegal'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Résultats de recherche, groupés par type',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'query', type: 'string', example: 'senegal'),
                        new OA\Property(property: 'results', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'type', type: 'string', enum: ['country', 'sector', 'source', 'report'], example: 'country'),
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'title', type: 'string', example: 'Senegal'),
                                new OA\Property(property: 'description', type: 'string', example: 'Pays (SEN) - Afrique de l\'Ouest'),
                                new OA\Property(property: 'destination', type: 'string', example: 'data.html?country=SEN'),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: '"q" absent, vide, ou hors des bornes de longueur',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'code', type: 'integer', example: 400),
                        new OA\Property(property: 'message', type: 'string', example: 'Parameter "q" must be at least 2 characters.'),
                    ]
                )
            ),
        ]
    )]
    public function search(Request $request): JsonResponse
    {
        $query = SearchQuery::fromQuery($request->query);

        return $this->json([
            'query' => $query->term,
            'results' => $this->searchService->search($query),
        ]);
    }
}
