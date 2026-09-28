<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TenderCriteria;
use App\Service\TenderService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A3.13 : "Tender Radar" - liste des appels à consultance (`Tender`,
 * libelle "Appel d'offres" renomme le 2026-09-28) émis par
 * des organismes liés au financement climatique. Distinct de
 * OpportunityController (C1.8, "appels à projets") - voir la docblock de
 * App\Entity\Tender pour la différence de fond. Réservé à ROLE_USER,
 * même raisonnement que /api/opportunities depuis la structuration des
 * accès (A3.12).
 */
final class TenderController extends AbstractController
{
    public function __construct(
        private readonly TenderService $tenderService,
    ) {
    }

    #[Route('/api/tenders', name: 'api_tenders', methods: ['GET'])]
    #[OA\Get(
        summary: 'Appels à consultance (marchés de prestation), filtrables par secteur/catégorie/échéance',
        description: 'Triés par échéance de dépôt des offres croissante (les plus urgents d\'abord). `status` (open/upcoming/closed) est calculé par rapport à maintenant, jamais stocké. `search` cherche dans le titre et l\'organisme émetteur, insensible à la casse et aux accents.',
        tags: ['Tenders'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'sector', in: 'query', required: false, description: 'Identifiant du secteur (Sector.id)', schema: new OA\Schema(type: 'integer', example: 3)),
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'Nature de la prestation', schema: new OA\Schema(type: 'string', enum: ['consulting', 'goods', 'works', 'services'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Statut par rapport à aujourd\'hui', schema: new OA\Schema(type: 'string', enum: ['open', 'upcoming', 'closed'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Terme de recherche dans le titre ou l\'organisme émetteur', schema: new OA\Schema(type: 'string', example: 'AfDB')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des appels à consultance',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'title', type: 'string', example: 'Étude de faisabilité - Mini-réseaux solaires en zone rurale'),
                                new OA\Property(property: 'issuingOrganization', type: 'string', example: 'Banque Africaine de Développement'),
                                new OA\Property(property: 'referenceNumber', type: 'string', nullable: true, example: 'AFDB-RFP-2026-0142'),
                                new OA\Property(property: 'category', type: 'string', enum: ['consulting', 'goods', 'works', 'services'], example: 'consulting'),
                                new OA\Property(property: 'sector', type: 'string', nullable: true, example: 'Renewable Energy'),
                                new OA\Property(property: 'description', type: 'string'),
                                new OA\Property(property: 'eligibilityCriteria', type: 'string'),
                                new OA\Property(property: 'estimatedValue', type: 'number', format: 'float', nullable: true, example: 450000),
                                new OA\Property(property: 'publishedAt', type: 'string', format: 'date'),
                                new OA\Property(property: 'submissionDeadline', type: 'string', format: 'date'),
                                new OA\Property(property: 'status', type: 'string', enum: ['open', 'upcoming', 'closed'], example: 'open'),
                                new OA\Property(property: 'documentsUrl', type: 'string', nullable: true),
                                new OA\Property(property: 'contactEmail', type: 'string', nullable: true),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 400, description: '"status"/"category" a une valeur hors de l\'énumération connue, ou "sector" n\'est pas un entier positif'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
        ]
    )]
    public function list(Request $request): JsonResponse
    {
        $criteria = TenderCriteria::fromQuery($request->query);

        return $this->json(['data' => $this->tenderService->list($criteria)]);
    }
}
