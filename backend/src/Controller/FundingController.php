<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\FundingSearchCriteria;
use App\Entity\Funding;
use App\Repository\FundingRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public read-only data extraction (A2.1 — cahier des charges 5.2.c):
 * filters are optional and cumulable, pagination is enforced server-side
 * with a hard cap on page size. Deliberately PUBLIC_ACCESS (see
 * security.yaml's access_control, added ahead of the general `^/api`
 * rule) — a visitor with no account can browse the dataset without a JWT
 * or an API key.
 */
final class FundingController extends AbstractController
{
    public function __construct(
        private readonly FundingRepository $fundingRepository,
    ) {
    }

    #[Route('/api/funding', name: 'api_funding_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Recherche paginée et filtrable dans les données de financement',
        description: 'Endpoint public (aucune authentification requise). Tous les filtres sont optionnels et cumulables. `limit` est plafonné à 100 (garde-fou de performance, cahier des charges 5.2.c) : une valeur supérieure est silencieusement ramenée à 100, jamais rejetée.',
        tags: ['Funding'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: false, description: "Code ISO alpha-3 du pays (Country.isoCode)", schema: new OA\Schema(type: 'string'), example: 'SEN'),
            new OA\Parameter(name: 'sector', in: 'query', required: false, description: 'Identifiant du secteur (Sector.id)', schema: new OA\Schema(type: 'integer'), example: 1),
            new OA\Parameter(name: 'year', in: 'query', required: false, description: "Année du financement", schema: new OA\Schema(type: 'integer'), example: 2025),
            new OA\Parameter(name: 'fundingType', in: 'query', required: false, description: 'public, private ou multilateral', schema: new OA\Schema(type: 'string', enum: ['public', 'private', 'multilateral']), example: 'public'),
            new OA\Parameter(name: 'periodStart', in: 'query', required: false, description: 'Date de collecte minimale (incluse), format YYYY-MM-DD', schema: new OA\Schema(type: 'string', format: 'date'), example: '2022-01-01'),
            new OA\Parameter(name: 'periodEnd', in: 'query', required: false, description: 'Date de collecte maximale (incluse), format YYYY-MM-DD', schema: new OA\Schema(type: 'string', format: 'date'), example: '2025-12-31'),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Numéro de page (défaut : 1)', schema: new OA\Schema(type: 'integer', default: 1), example: 1),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Taille de page (défaut : 20, plafond : 100)', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100), example: 20),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste paginée des financements correspondant aux filtres',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'country', properties: [
                                        new OA\Property(property: 'name', type: 'string', example: 'Senegal'),
                                        new OA\Property(property: 'isoCode', type: 'string', example: 'SEN'),
                                    ], type: 'object'),
                                    new OA\Property(property: 'sector', properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 1),
                                        new OA\Property(property: 'name', type: 'string', example: 'Renewable Energy'),
                                    ], type: 'object'),
                                    new OA\Property(property: 'year', type: 'integer', example: 2025),
                                    new OA\Property(property: 'amount', type: 'string', example: '4032000.00'),
                                    new OA\Property(property: 'fundingType', type: 'string', example: 'public'),
                                    new OA\Property(property: 'source', properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 1),
                                        new OA\Property(property: 'name', type: 'string', example: 'World Bank Data API'),
                                    ], type: 'object'),
                                    new OA\Property(property: 'collectionDate', type: 'string', format: 'date', example: '2025-03-15'),
                                    new OA\Property(property: 'validationStatus', type: 'string', example: 'demo'),
                                ]
                            )
                        ),
                        new OA\Property(property: 'meta', properties: [
                            new OA\Property(property: 'page', type: 'integer', example: 1),
                            new OA\Property(property: 'limit', type: 'integer', example: 20),
                            new OA\Property(property: 'total', type: 'integer', example: 1080),
                            new OA\Property(property: 'totalPages', type: 'integer', example: 54),
                        ], type: 'object'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Paramètre de filtre ou de pagination invalide',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'code', type: 'integer', example: 400),
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid value for parameter "fundingType": must be one of public, private, multilateral.'),
                    ]
                )
            ),
        ]
    )]
    public function list(Request $request): JsonResponse
    {
        $criteria = FundingSearchCriteria::fromQuery($request->query);

        $items = $this->fundingRepository->findByCriteria($criteria);
        $total = $this->fundingRepository->countByCriteria($criteria);

        return $this->json([
            'data' => array_map(self::toListItem(...), $items),
            'meta' => [
                'page' => $criteria->page,
                'limit' => $criteria->limit,
                'total' => $total,
                'totalPages' => $total > 0 ? (int) ceil($total / $criteria->limit) : 0,
            ],
        ]);
    }

    /**
     * @return array{
     *     id: int|null,
     *     country: array{name: string, isoCode: string},
     *     sector: array{id: int|null, name: string},
     *     year: int,
     *     amount: string,
     *     fundingType: string,
     *     source: array{id: int|null, name: string},
     *     collectionDate: string,
     *     validationStatus: string,
     * }
     */
    private static function toListItem(Funding $funding): array
    {
        return [
            'id' => $funding->getId(),
            'country' => [
                'name' => $funding->getCountry()->getName(),
                'isoCode' => $funding->getCountry()->getIsoCode(),
            ],
            'sector' => [
                'id' => $funding->getSector()->getId(),
                'name' => $funding->getSector()->getName(),
            ],
            'year' => $funding->getYear(),
            'amount' => $funding->getAmount(),
            'fundingType' => $funding->getFundingType()->value,
            'source' => [
                'id' => $funding->getSource()->getId(),
                'name' => $funding->getSource()->getName(),
            ],
            'collectionDate' => $funding->getCollectionDate()->format('Y-m-d'),
            'validationStatus' => $funding->getValidationStatus()->value,
        ];
    }
}
