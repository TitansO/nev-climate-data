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
 * Global search (A2.8) across Country, Sector, Source, published Report,
 * and (C1.3) Project/Opportunity - see App\Service\SearchService's
 * docblock for why the scope stops there, and for the full facet-support
 * matrix. Deliberately PUBLIC_ACCESS, like GET /api/funding and
 * GET /api/analytics/*: every searched entity is already public data
 * (Country/Sector/Source are exposed via GET /api/funding's responses;
 * Report search excludes Draft; Project/Opportunity are F4's explicitly
 * demo-labeled fixtures).
 */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {
    }

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    #[OA\Get(
        summary: 'Recherche globale (pays, secteurs, sources, rapports publiés, projets, appels à projets)',
        description: 'Recherche insensible à la casse et aux accents, sur le nom/titre de chaque type. `q` est requis, entre 2 et 100 caractères (espaces superflus ignorés). Au plus 5 résultats par type, triés par ordre alphabétique au sein de chaque type. Les rapports en statut "draft" ne sont jamais retournés. C1.3 : filtres optionnels et cumulables country/sector/instrument/year/minAmount - chacun ne s\'applique qu\'aux types qui portent réellement cette dimension (voir App\\Service\\SearchService) ; un type qui n\'a pas un filtre actif en est simplement exclu.',
        tags: ['Search'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: true, description: 'Terme recherché (2 à 100 caractères)', schema: new OA\Schema(type: 'string'), example: 'senegal'),
            new OA\Parameter(name: 'country', in: 'query', required: false, description: 'Code ISO du pays (country, report, project)', schema: new OA\Schema(type: 'string', example: 'SEN')),
            new OA\Parameter(name: 'sector', in: 'query', required: false, description: 'Identifiant du secteur (sector, project, opportunity)', schema: new OA\Schema(type: 'integer', example: 3)),
            new OA\Parameter(name: 'instrument', in: 'query', required: false, description: 'Instrument financier recherché (project uniquement)', schema: new OA\Schema(type: 'string', enum: ['grant', 'loan_concessional', 'loan_non_concessional', 'guarantee', 'equity', 'results_based', 'unknown'])),
            new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Année (report : publicationDate ; project : createdAt ; opportunity : applicationWindowOpensAt)', schema: new OA\Schema(type: 'integer', example: 2025)),
            new OA\Parameter(name: 'minAmount', in: 'query', required: false, description: 'Montant minimum (project.amountSought / opportunity.envelopeAmount)', schema: new OA\Schema(type: 'number', format: 'float', example: 100000)),
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
                                new OA\Property(property: 'type', type: 'string', enum: ['country', 'sector', 'source', 'report', 'project', 'opportunity'], example: 'country'),
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
                description: '"q" absent/hors bornes, ou un filtre a une valeur invalide (ex : "instrument" hors de l\'énumération connue)',
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
