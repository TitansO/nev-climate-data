<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\OpportunityCriteria;
use App\Service\OpportunityService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C1.8 : "Opportunity Radar" - liste des appels à projets (`Opportunity`,
 * F4) ouverts par les bailleurs. Saisie manuelle pour ce sprint (voir
 * App\DataFixtures\OpportunityFixtures) - aucun connecteur automatique par
 * bailleur n'existe encore. Deliberately PUBLIC_ACCESS, même raisonnement
 * que GET /api/funding et GET /api/search : cette donnée n'a aucune
 * raison d'exiger une connexion.
 */
final class OpportunityController extends AbstractController
{
    public function __construct(
        private readonly OpportunityService $opportunityService,
    ) {
    }

    #[Route('/api/opportunities', name: 'api_opportunities', methods: ['GET'])]
    #[OA\Get(
        summary: 'Appels à projets ouverts, filtrables par secteur/pays/échéance',
        description: 'Triés par échéance croissante (les plus urgents d\'abord ; sans date de fermeture, en dernier). `status` (open/upcoming/closed) est calculé par rapport à maintenant, jamais stocké. `country` est une recherche texte insensible à la casse et aux accents dans le texte libre d\'éligibilité (`eligibilityCriteria`) - chaque appel à projets réel est régional/multi-pays ("tous pays NEV", "les 11 pays du G5 Sahel"...), il n\'existe donc pas de pays éligible unique à stocker par ligne (voir App\\Dto\\OpportunityCriteria).',
        tags: ['Opportunities'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'sector', in: 'query', required: false, description: 'Identifiant du secteur (Sector.id)', schema: new OA\Schema(type: 'integer', example: 3)),
            new OA\Parameter(name: 'country', in: 'query', required: false, description: 'Terme de recherche dans le texte d\'éligibilité (ex : "Senegal", "Sahel")', schema: new OA\Schema(type: 'string', example: 'Sahel')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Statut par rapport à aujourd\'hui', schema: new OA\Schema(type: 'string', enum: ['open', 'upcoming', 'closed'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des appels à projets',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 4),
                                new OA\Property(property: 'title', type: 'string', example: 'GCF Adaptation Fund - Coastal Resilience'),
                                new OA\Property(property: 'sourceName', type: 'string', example: 'Green Climate Fund - IATI Datastore'),
                                new OA\Property(property: 'sector', type: 'string', nullable: true, example: 'Adaptation'),
                                new OA\Property(property: 'eligibilityCriteria', type: 'string', example: 'Pays éligibles : petits États insulaires...'),
                                new OA\Property(property: 'envelopeAmount', type: 'number', format: 'float', example: 40000000),
                                new OA\Property(property: 'applicationWindowOpensAt', type: 'string', format: 'date', nullable: true, example: '2026-10-01'),
                                new OA\Property(property: 'applicationWindowClosesAt', type: 'string', format: 'date', nullable: true, example: '2027-03-31'),
                                new OA\Property(property: 'status', type: 'string', enum: ['open', 'upcoming', 'closed'], example: 'upcoming'),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 400, description: '"status" a une valeur hors de l\'énumération connue, ou "sector" n\'est pas un entier positif'),
        ]
    )]
    public function list(Request $request): JsonResponse
    {
        $criteria = OpportunityCriteria::fromQuery($request->query);

        return $this->json(['data' => $this->opportunityService->list($criteria)]);
    }
}
