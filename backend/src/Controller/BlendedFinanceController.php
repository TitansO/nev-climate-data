<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Sector;
use App\Repository\SectorRepository;
use App\Service\BlendedFinanceService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C3.4 : "Blended Finance Simulator" - POST /api/blended/simulate. Comme
 * C2.5 (ImpactController), un pur calcul paramétrique sans écriture ni
 * donnée sensible - PUBLIC_ACCESS, même ouverture que le reste des outils
 * d'exploration de la plateforme.
 */
final class BlendedFinanceController extends AbstractController
{
    public function __construct(
        private readonly SectorRepository $sectorRepository,
        private readonly BlendedFinanceService $blendedFinanceService,
    ) {
    }

    #[Route('/api/blended/simulate', name: 'api_blended_simulate', methods: ['POST'])]
    #[OA\Post(
        summary: 'Simule un montage de finance mixte (public + privé) et son effet de levier attendu',
        description: 'Modèle paramétrique (App\\Service\\BlendedFinanceService), jamais un modèle entraîné - ratios de levier illustratifs par secteur et par instrument, inspirés de la littérature OCDE. `instrumentMix` : pourcentage (0.0-1.0) par instrument (App\\Entity\\Enum\\FundingInstrument), doit sommer à 1.0 ± 0,01 ; omis ou vide, un multiplicateur neutre (1.0) est appliqué. `country` accepté mais sans effet sur le calcul pour l\'instant.',
        tags: ['Blended Finance'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sector', 'publicAmount'],
                properties: [
                    new OA\Property(property: 'sector', type: 'integer', description: 'Identifiant du secteur (Sector.id)', example: 1),
                    new OA\Property(property: 'publicAmount', type: 'number', format: 'float', example: 5000000),
                    new OA\Property(property: 'instrumentMix', type: 'object', nullable: true, description: 'Pourcentage (0.0-1.0) par instrument, sommant à 1.0', example: ['guarantee' => 0.7, 'grant' => 0.3]),
                    new OA\Property(property: 'country', type: 'string', nullable: true, description: 'Code ISO-3 (accepté, sans effet sur le calcul)', example: 'SEN'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Simulation',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'sector', type: 'string'),
                    new OA\Property(property: 'publicAmountUsd', type: 'number'),
                    new OA\Property(property: 'leverageRatio', type: 'number', format: 'float', example: 2.94),
                    new OA\Property(property: 'privateAmountMobilizedUsd', type: 'number'),
                    new OA\Property(property: 'totalMobilizedUsd', type: 'number'),
                    new OA\Property(property: 'waterfall', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'stage', type: 'string'),
                            new OA\Property(property: 'amountUsd', type: 'number'),
                        ]
                    )),
                    new OA\Property(property: 'methodologySource', type: 'string'),
                ])
            ),
            new OA\Response(response: 400, description: '"sector"/"publicAmount" manquant ou invalide, ou "instrumentMix" ne somme pas à 1.0'),
        ]
    )]
    public function simulate(Request $request): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Corps de requête JSON invalide.');
        }

        $sector = $this->parseSector($decoded['sector'] ?? null);
        $publicAmount = self::parseAmount($decoded['publicAmount'] ?? null);
        $instrumentMix = self::parseInstrumentMix($decoded['instrumentMix'] ?? null);

        return $this->json($this->blendedFinanceService->simulate($sector, $publicAmount, $instrumentMix));
    }

    private function parseSector(mixed $raw): Sector
    {
        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw < 1) {
            throw new BadRequestHttpException('Le champ "sector" doit être un identifiant de secteur valide.');
        }

        $sector = $this->sectorRepository->find((int) $raw);
        if (null === $sector) {
            throw new BadRequestHttpException('Le champ "sector" ne correspond à aucun secteur connu.');
        }

        return $sector;
    }

    private static function parseAmount(mixed $raw): float
    {
        if (!is_numeric($raw) || (float) $raw <= 0.0) {
            throw new BadRequestHttpException('Le champ "publicAmount" doit être un nombre positif.');
        }

        return (float) $raw;
    }

    /**
     * @return array<string, float>
     */
    private static function parseInstrumentMix(mixed $raw): array
    {
        if (null === $raw) {
            return [];
        }
        if (!\is_array($raw) || array_is_list($raw)) {
            throw new BadRequestHttpException('Le champ "instrumentMix" doit être un objet {instrument: pourcentage}.');
        }

        $knownInstruments = array_map(static fn (FundingInstrument $case) => $case->value, FundingInstrument::cases());
        $mix = [];
        $sum = 0.0;
        foreach ($raw as $instrument => $share) {
            if (!\in_array($instrument, $knownInstruments, true)) {
                throw new BadRequestHttpException(\sprintf('Le champ "instrumentMix" contient un instrument inconnu : "%s".', (string) $instrument));
            }
            if (!is_numeric($share) || (float) $share < 0.0) {
                throw new BadRequestHttpException(\sprintf('Le pourcentage de "instrumentMix.%s" doit être un nombre positif ou nul.', $instrument));
            }
            $mix[$instrument] = (float) $share;
            $sum += (float) $share;
        }

        if (abs($sum - 1.0) > 0.01) {
            throw new BadRequestHttpException('Le champ "instrumentMix" doit sommer à 1.0 (100%).');
        }

        return $mix;
    }
}
