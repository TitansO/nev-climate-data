<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\OpportunityCriteria;
use App\Repository\OpportunityRepository;
use App\Service\OpportunityService;
use App\Service\ProjectMatchingService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C1.8 : "Opportunity Radar" - liste des appels à projets (`Opportunity`,
 * F4) ouverts par les bailleurs. Saisie manuelle pour ce sprint (voir
 * App\DataFixtures\OpportunityFixtures) - aucun connecteur automatique par
 * bailleur n'existe encore. Réservé à ROLE_USER depuis la structuration
 * des accès (A3.12, 2026-09-24) - voir config/packages/security.yaml ;
 * cette docblock disait auparavant "deliberately PUBLIC_ACCESS", devenu
 * faux depuis ce changement et corrigé au passage.
 */
final class OpportunityController extends AbstractController
{
    public function __construct(
        private readonly OpportunityService $opportunityService,
        private readonly OpportunityRepository $opportunityRepository,
        private readonly ProjectMatchingService $matchingService,
    ) {
    }

    #[Route('/api/opportunities', name: 'api_opportunities', methods: ['GET'])]
    #[OA\Get(
        summary: 'Appels à projets ouverts, filtrables par secteur/pays/échéance',
        description: 'Triés par échéance croissante (les plus urgents d\'abord ; sans date de fermeture, en dernier). `status` (open/upcoming/closed) est calculé par rapport à maintenant, jamais stocké. `country` est une recherche texte insensible à la casse et aux accents dans le texte libre d\'éligibilité (`eligibilityCriteria`) - chaque appel à projets réel est régional/multi-pays ("tous pays NEV", "les 11 pays du G5 Sahel"...), il n\'existe donc pas de pays éligible unique à stocker par ligne (voir App\\Dto\\OpportunityCriteria).',
        tags: ['Opportunities'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'sector', in: 'query', required: false, description: 'Identifiant du secteur (Sector.id)', schema: new OA\Schema(type: 'integer', example: 3)),
            new OA\Parameter(name: 'country', in: 'query', required: false, description: 'Terme de recherche dans le texte d\'éligibilité (ex : "Senegal", "Sahel")', schema: new OA\Schema(type: 'string', example: 'Sahel')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Statut par rapport à aujourd\'hui', schema: new OA\Schema(type: 'string', enum: ['open', 'upcoming', 'closed'])),
            new OA\Parameter(name: 'instrument', in: 'query', required: false, description: 'Type d\'instrument financier proposé (A3.12)', schema: new OA\Schema(type: 'string', enum: ['grant', 'loan_concessional', 'loan_non_concessional', 'guarantee', 'equity', 'results_based', 'unknown'])),
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
                                new OA\Property(property: 'instrument', type: 'string', nullable: true, enum: ['grant', 'loan_concessional', 'loan_non_concessional', 'guarantee', 'equity', 'results_based', 'unknown'], example: 'grant'),
                                new OA\Property(property: 'applicationUrl', type: 'string', nullable: true, example: 'https://www.greenclimate.fund/sap'),
                                new OA\Property(property: 'contactEmail', type: 'string', nullable: true, example: 'sap@gcfund.org'),
                                new OA\Property(property: 'requiredDocuments', type: 'string', nullable: true, example: 'Note conceptuelle, lettre de non-objection du point focal national, budget prévisionnel.'),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 400, description: '"status"/"instrument" a une valeur hors de l\'énumération connue, ou "sector" n\'est pas un entier positif'),
        ]
    )]
    public function list(Request $request): JsonResponse
    {
        $criteria = OpportunityCriteria::fromQuery($request->query);

        return $this->json(['data' => $this->opportunityService->list($criteria)]);
    }

    #[Route('/api/opportunities/{id}/candidates', name: 'api_opportunities_candidates', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        summary: 'Classe les projets du pipeline les plus pertinents pour cette opportunité',
        description: 'Sens inverse de GET /api/projects/{id}/matches - même score pondéré transparent (App\\Service\\ProjectMatchingService), même poids. Donnée de pipeline interne (comme /matches), pas la liste d\'opportunités elle-même : requiert une authentification, contrairement à GET /api/opportunities.',
        tags: ['Opportunities'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Nombre maximum de résultats (défaut 10, plafond 25)', schema: new OA\Schema(type: 'integer', default: 10, maximum: 25)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Projets classés par score décroissant',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'projectId', type: 'integer'),
                            new OA\Property(property: 'projectTitle', type: 'string'),
                            new OA\Property(property: 'country', type: 'string'),
                            new OA\Property(property: 'sector', type: 'string'),
                            new OA\Property(property: 'amountSought', type: 'number'),
                            new OA\Property(property: 'pipelineStage', type: 'string'),
                            new OA\Property(property: 'score', type: 'number', format: 'float', example: 0.775),
                            new OA\Property(property: 'breakdown', properties: [
                                new OA\Property(property: 'sector', type: 'number'),
                                new OA\Property(property: 'country', type: 'number'),
                                new OA\Property(property: 'instrument', type: 'number'),
                                new OA\Property(property: 'amount', type: 'number'),
                                new OA\Property(property: 'window', type: 'number'),
                            ], type: 'object'),
                        ]
                    )),
                ])
            ),
            new OA\Response(response: 400, description: '"limit" invalide'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 404, description: 'Opportunité introuvable'),
        ]
    )]
    public function candidates(int $id, Request $request): JsonResponse
    {
        $opportunity = $this->opportunityRepository->find($id);
        if (null === $opportunity) {
            throw $this->createNotFoundException('Opportunity not found.');
        }

        $limit = self::parseLimit($request->query->get('limit'));

        return $this->json(['data' => $this->matchingService->candidatesForOpportunity($opportunity, $limit)]);
    }

    private static function parseLimit(?string $raw): int
    {
        if (null === $raw || '' === $raw) {
            return ProjectMatchingService::DEFAULT_LIMIT;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw < 1) {
            throw new BadRequestHttpException('Invalid value for parameter "limit": must be a positive integer.');
        }

        return min((int) $raw, ProjectMatchingService::MAX_LIMIT);
    }
}
