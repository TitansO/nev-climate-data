<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SourceRepository;
use App\Service\InstitutionService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C1.4 : fiche institution (bailleur) - un `Source` vu à travers les
 * financements qu'il a déclarés (portefeuille, répartition, évolution,
 * fiabilité, transparence). Deliberately PUBLIC_ACCESS, même raisonnement
 * que GET /api/funding et GET /api/analytics/* : chaque `Source` est déjà
 * exposée par ces deux endpoints, il n'y a donc aucune raison d'exiger une
 * connexion pour la voir sous cet angle-ci.
 */
final class InstitutionController extends AbstractController
{
    public function __construct(
        private readonly SourceRepository $sourceRepository,
        private readonly InstitutionService $institutionService,
    ) {
    }

    #[Route('/api/institutions/{sourceId}', name: 'api_institution_profile', requirements: ['sourceId' => '\d+'], methods: ['GET'])]
    #[OA\Get(
        summary: 'Fiche institution (bailleur) : portefeuille, répartition, évolution, fiabilité, transparence (mis en cache 15 min)',
        description: 'Vue d\'un `Source` à travers ses financements réels (jamais les données de démonstration - B3.3). `portfolio` est `null` si cette source n\'a encore aucun financement réel (jamais un zéro fabriqué). `reliability` reprend Source.reliability tel quel. `transparency` reflète la dernière collecte réelle connue pour cette seule source (même notion que GET /api/analytics/data-freshness, C1.1) - une donnée vérifiable, jamais un score inventé.',
        tags: ['Institutions'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fiche institution',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 12),
                        new OA\Property(property: 'name', type: 'string', example: 'World Bank Data API'),
                        new OA\Property(property: 'type', type: 'string', example: 'official_api'),
                        new OA\Property(property: 'reliability', type: 'string', enum: ['low', 'medium', 'high'], example: 'high'),
                        new OA\Property(
                            property: 'portfolio',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'totalAmountUsd', type: 'number', format: 'float', example: 45000000),
                                new OA\Property(property: 'fundingRecordCount', type: 'integer', example: 128),
                                new OA\Property(property: 'countriesFundedCount', type: 'integer', example: 22),
                                new OA\Property(property: 'sectorsFundedCount', type: 'integer', example: 5),
                            ]
                        ),
                        new OA\Property(property: 'sectorBreakdown', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'sector', type: 'string', example: 'Renewable Energy'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 12000000),
                                new OA\Property(property: 'percentage', type: 'number', format: 'float', example: 26.7),
                            ]
                        )),
                        new OA\Property(property: 'countryBreakdown', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'isoCode', type: 'string', example: 'SEN'),
                                new OA\Property(property: 'country', type: 'string', example: 'Senegal'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 3000000),
                                new OA\Property(property: 'percentage', type: 'number', format: 'float', example: 6.7),
                            ]
                        )),
                        new OA\Property(property: 'yearlyTrend', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'period', type: 'integer', example: 2025),
                                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 9000000),
                            ]
                        )),
                        new OA\Property(
                            property: 'transparency',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'lastCollectedAt', type: 'string', format: 'date', nullable: true, example: '2026-09-12'),
                                new OA\Property(property: 'daysSinceCollection', type: 'integer', nullable: true, example: 3),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Institution (source) introuvable'),
        ]
    )]
    public function profile(int $sourceId): JsonResponse
    {
        $source = $this->sourceRepository->find($sourceId);
        if (null === $source) {
            throw $this->createNotFoundException('Institution not found.');
        }

        return $this->json($this->institutionService->getProfile($source));
    }
}
