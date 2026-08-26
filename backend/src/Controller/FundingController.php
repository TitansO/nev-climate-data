<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\FundingExportFormat;
use App\Dto\FundingSearchCriteria;
use App\Entity\Funding;
use App\Repository\FundingRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public read-only data extraction (A2.1 — cahier des charges 5.2.c):
 * filters are optional and cumulable, pagination is enforced server-side
 * with a hard cap on page size. Deliberately PUBLIC_ACCESS (see
 * security.yaml's access_control, added ahead of the general `^/api`
 * rule) — a visitor with no account can browse the dataset without a JWT
 * or an API key.
 *
 * export() (A2.3) is the one exception: it requires an authenticated user
 * (JWT or API key - either satisfies the firewall's default
 * IS_AUTHENTICATED_FULLY, which security.yaml applies to it explicitly
 * ahead of the /api/funding PUBLIC_ACCESS rule, since that rule matches by
 * prefix and would otherwise also cover /api/funding/export).
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

    #[Route('/api/funding/export', name: 'api_funding_export', methods: ['GET'])]
    #[OA\Get(
        summary: 'Exporte les données de financement correspondant aux filtres, au format CSV',
        description: 'Réservé aux utilisateurs authentifiés (JWT ou clé API). Accepte exactement les mêmes filtres que `GET /api/funding` (`country`, `sector`, `year`, `fundingType`, `periodStart`, `periodEnd`) - sans pagination : le fichier contient toutes les lignes correspondantes, pas une seule page. `format` ne supporte actuellement que `csv`.',
        tags: ['Funding'],
        security: [['bearerAuth' => []], ['apiKeyAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: false, description: "Code ISO alpha-3 du pays (Country.isoCode)", schema: new OA\Schema(type: 'string'), example: 'SEN'),
            new OA\Parameter(name: 'sector', in: 'query', required: false, description: 'Identifiant du secteur (Sector.id)', schema: new OA\Schema(type: 'integer'), example: 1),
            new OA\Parameter(name: 'year', in: 'query', required: false, description: "Année du financement", schema: new OA\Schema(type: 'integer'), example: 2025),
            new OA\Parameter(name: 'fundingType', in: 'query', required: false, description: 'public, private ou multilateral', schema: new OA\Schema(type: 'string', enum: ['public', 'private', 'multilateral']), example: 'public'),
            new OA\Parameter(name: 'periodStart', in: 'query', required: false, description: 'Date de collecte minimale (incluse), format YYYY-MM-DD', schema: new OA\Schema(type: 'string', format: 'date'), example: '2022-01-01'),
            new OA\Parameter(name: 'periodEnd', in: 'query', required: false, description: 'Date de collecte maximale (incluse), format YYYY-MM-DD', schema: new OA\Schema(type: 'string', format: 'date'), example: '2025-12-31'),
            new OA\Parameter(name: 'format', in: 'query', required: false, description: 'Format du fichier exporté (seul "csv" est supporté)', schema: new OA\Schema(type: 'string', enum: ['csv'], default: 'csv'), example: 'csv'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fichier CSV en pièce jointe', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 400, description: 'Paramètre de filtre ou de format invalide'),
            new OA\Response(response: 401, description: 'Authentification requise (JWT ou clé API)'),
        ]
    )]
    public function export(Request $request): Response
    {
        $format = FundingExportFormat::fromQuery($request->query);
        $criteria = FundingSearchCriteria::fromQuery($request->query);
        $items = $this->fundingRepository->streamByCriteria($criteria);

        // Built in memory (php://memory), not streamed straight to the HTTP
        // response: at the project's current scale (low thousands of rows)
        // this is simpler and fully testable via Response::getContent() -
        // StreamedResponse::getContent() always returns false by design,
        // which functional tests can't assert against. toIterable() on the
        // repository side still avoids hydrating the whole result set as
        // Doctrine entities in memory at once.
        $handle = fopen('php://memory', 'r+');
        // UTF-8 BOM: without it, Excel (the realistic consumer of a CSV
        // export of French-language content) misreads accented characters
        // as a different encoding on open. escape: '' (RFC 4180 - quotes
        // doubled, no backslash-escaping) rather than PHP's legacy default,
        // which PHP 8.4 deprecates leaving implicit.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['id', 'country_name', 'country_iso_code', 'sector_id', 'sector_name', 'year', 'amount', 'funding_type', 'source_id', 'source_name', 'collection_date', 'validation_status'], escape: '');
        foreach ($items as $funding) {
            fputcsv($handle, self::toCsvRow($funding), escape: '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            \sprintf('funding-export-%s.%s', (new \DateTimeImmutable())->format('Y-m-d-His'), $format->value)
        ));

        return $response;
    }

    /**
     * @return list<string>
     */
    private static function toCsvRow(Funding $funding): array
    {
        return [
            (string) $funding->getId(),
            $funding->getCountry()->getName(),
            $funding->getCountry()->getIsoCode(),
            (string) $funding->getSector()->getId(),
            $funding->getSector()->getName(),
            (string) $funding->getYear(),
            $funding->getAmount(),
            $funding->getFundingType()->value,
            (string) $funding->getSource()->getId(),
            $funding->getSource()->getName(),
            $funding->getCollectionDate()->format('Y-m-d'),
            $funding->getValidationStatus()->value,
        ];
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
