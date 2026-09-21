<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CountryRepository;
use App\Service\InvestmentRiskScoreService;
use App\Service\NdcAlignmentScoreService;
use App\Service\Scoring\ScoreService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C2.6 : scores par pays. `Country` est déjà une donnée publique partout
 * ailleurs sur cette plateforme (GET /api/analytics/*, GET /api/search) -
 * même raisonnement ici, PUBLIC_ACCESS (voir security.yaml).
 */
final class CountryController extends AbstractController
{
    public function __construct(
        private readonly CountryRepository $countryRepository,
        private readonly NdcAlignmentScoreService $ndcAlignmentScoreService,
        private readonly InvestmentRiskScoreService $investmentRiskScoreService,
    ) {
    }

    #[Route('/api/countries/{iso}/scores/ndc-alignment', name: 'api_countries_ndc_alignment_score', methods: ['GET'], requirements: ['iso' => '[A-Za-z]{3}'])]
    #[OA\Get(
        summary: 'Score d\'alignement CDN du pays (0-100, grade A-E)',
        description: '3 indicateurs pondérés transparents (couverture des secteurs prioritaires 0,5 ; ratio reçu/besoin déclaré 0,3 ; conditionnalité de la cible 0,2). `available=false` (jamais un score fabriqué) quand ce pays n\'a pas de CDN chiffrée dans le jeu de données (F7, ~12/54 pays NEV).',
        tags: ['Countries'],
        security: [],
        parameters: [new OA\Parameter(name: 'iso', in: 'path', required: true, description: 'Code ISO-3 du pays', schema: new OA\Schema(type: 'string', example: 'SEN'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Score d\'alignement CDN, ou indisponibilité explicite',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'available', type: 'boolean'),
                    new OA\Property(property: 'subjectType', type: 'string', example: 'country', nullable: true),
                    new OA\Property(property: 'subjectId', type: 'integer', nullable: true),
                    new OA\Property(property: 'scoreType', type: 'string', example: 'ndc_alignment', nullable: true),
                    new OA\Property(property: 'value', type: 'number', format: 'float', example: 61.2, nullable: true),
                    new OA\Property(property: 'grade', type: 'string', enum: ['A', 'B', 'C', 'D', 'E'], nullable: true),
                    new OA\Property(property: 'breakdown', type: 'array', nullable: true, items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'code', type: 'string'),
                            new OA\Property(property: 'value', type: 'number'),
                            new OA\Property(property: 'weight', type: 'number'),
                            new OA\Property(property: 'explanation', type: 'string'),
                        ]
                    )),
                    new OA\Property(property: 'computedAt', type: 'string', format: 'date-time', nullable: true),
                ])
            ),
            new OA\Response(response: 404, description: 'Code ISO inconnu'),
        ]
    )]
    public function ndcAlignmentScore(string $iso): JsonResponse
    {
        $country = $this->countryRepository->findOneByIsoCode($iso);
        if (null === $country) {
            throw $this->createNotFoundException('Country not found.');
        }

        $score = $this->ndcAlignmentScoreService->scoreFor($country);
        if (null === $score) {
            return $this->json(['available' => false]);
        }

        return $this->json(array_merge(['available' => true], ScoreService::toArray($score)));
    }

    #[Route('/api/countries/{iso}/scores/investment-risk', name: 'api_countries_investment_risk_score', methods: ['GET'], requirements: ['iso' => '[A-Za-z]{3}'])]
    #[OA\Get(
        summary: 'Score de risque d\'investissement du pays (0-100, grade A-E - A = le plus sûr)',
        description: 'Risque pays à partir d\'indicateurs ouverts (volatilité des flux réels, concentration des bailleurs, écart CDN) - App\\Service\\Scoring\\CountryRiskCalculator, réutilisé tel quel depuis C2.4. Aucun indice souverain tiers. Grade orienté "plus haut = plus sûr", comme tous les autres scores de cette plateforme.',
        tags: ['Countries'],
        security: [],
        parameters: [new OA\Parameter(name: 'iso', in: 'path', required: true, description: 'Code ISO-3 du pays', schema: new OA\Schema(type: 'string', example: 'SEN'))],
        responses: [
            new OA\Response(response: 200, description: 'Score de risque d\'investissement'),
            new OA\Response(response: 404, description: 'Code ISO inconnu'),
        ]
    )]
    public function investmentRiskScore(string $iso): JsonResponse
    {
        $country = $this->countryRepository->findOneByIsoCode($iso);
        if (null === $country) {
            throw $this->createNotFoundException('Country not found.');
        }

        return $this->json(ScoreService::toArray($this->investmentRiskScoreService->scoreForCountry($country)));
    }
}
