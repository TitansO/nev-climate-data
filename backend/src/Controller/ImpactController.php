<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Sector;
use App\Repository\SectorRepository;
use App\Service\ImpactCalculatorService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C2.5 : "Impact Calculator" - estime l'impact d'un montant (tCO2
 * évitées/an, MW installés, bénéficiaires) selon le secteur, à partir de
 * facteurs statiques documentés (App\Entity\ImpactFactor, F4-style
 * "aucun modèle entraîné"). Audit 2026-10-01 : ce docblock affirmait
 * PUBLIC_ACCESS, resté faux depuis la structuration des accès du
 * 2026-09-24 - /api/sectors est explicitement ROLE_USER et
 * /api/impact/estimate hérite du catch-all IS_AUTHENTICATED_FULLY
 * (security.yaml), ni l'un ni l'autre n'a de règle PUBLIC_ACCESS dédiée.
 */
final class ImpactController extends AbstractController
{
    public function __construct(
        private readonly SectorRepository $sectorRepository,
        private readonly ImpactCalculatorService $impactCalculatorService,
    ) {
    }

    #[Route('/api/sectors', name: 'api_sectors_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Liste les secteurs (référentiel) - notamment pour peupler le sélecteur du calculateur d\'impact',
        tags: ['Impact'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Secteurs triés par nom',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'name', type: 'string'),
                        ]
                    )),
                ])
            ),
        ]
    )]
    public function sectors(): JsonResponse
    {
        $sectors = $this->sectorRepository->findBy([], ['name' => 'ASC']);

        return $this->json(['data' => array_map(static fn (Sector $sector) => ['id' => $sector->getId(), 'name' => $sector->getName()], $sectors)]);
    }

    #[Route('/api/impact/estimate', name: 'api_impact_estimate', methods: ['POST'])]
    #[OA\Post(
        summary: 'Estime l\'impact d\'un montant investi dans un secteur (tCO2/an, MW, bénéficiaires)',
        description: 'Facteurs statiques et documentés par secteur (App\\Entity\\ImpactFactor), jamais un modèle entraîné - chaque métrique renvoyée porte une fourchette basse/haute, jamais un chiffre ponctuel présenté comme certain. `country` accepté mais actuellement sans effet sur le calcul (aucun facteur d\'ajustement par pays n\'existe encore).',
        tags: ['Impact'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sector', 'amountUsd'],
                properties: [
                    new OA\Property(property: 'sector', type: 'integer', description: 'Identifiant du secteur (Sector.id)', example: 1),
                    new OA\Property(property: 'amountUsd', type: 'number', format: 'float', example: 5000000),
                    new OA\Property(property: 'country', type: 'string', nullable: true, description: 'Code ISO-3 (accepté, sans effet sur le calcul pour l\'instant)', example: 'SEN'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estimation (ou indisponibilité si aucun facteur n\'existe pour ce secteur)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'available', type: 'boolean'),
                    new OA\Property(property: 'sector', type: 'string', nullable: true),
                    new OA\Property(property: 'amountUsd', type: 'number', nullable: true),
                    new OA\Property(property: 'estimates', type: 'object', nullable: true, description: 'Une clé par métrique disponible pour ce secteur (co2AvoidedTonsPerYear, mwInstalled, beneficiaries), chacune {central, low, high}'),
                    new OA\Property(property: 'uncertaintyPercent', type: 'number', nullable: true),
                    new OA\Property(property: 'methodologySource', type: 'string', nullable: true),
                ])
            ),
            new OA\Response(response: 400, description: '"sector" ou "amountUsd" manquant ou invalide'),
        ]
    )]
    public function estimate(Request $request): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Corps de requête JSON invalide.');
        }

        $sector = $this->parseSector($decoded['sector'] ?? null);
        $amountUsd = self::parseAmount($decoded['amountUsd'] ?? null);

        return $this->json($this->impactCalculatorService->estimate($sector, $amountUsd));
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
            throw new BadRequestHttpException('Le champ "amountUsd" doit être un nombre positif.');
        }

        return (float) $raw;
    }
}
