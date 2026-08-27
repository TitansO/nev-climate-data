<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\FundingExportFormat;
use App\Dto\FundingSearchCriteria;
use App\Entity\Enum\ExportStatus;
use App\Entity\Funding;
use App\Repository\ExportRepository;
use App\Repository\FundingRepository;
use App\Security\ExportQuotaPolicy;
use App\Service\ExportService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public read-only data extraction (A2.1 - cahier des charges 5.2.c):
 * filters are optional and cumulable, pagination is enforced server-side
 * with a hard cap on page size. Deliberately PUBLIC_ACCESS (see
 * security.yaml's access_control, added ahead of the general `^/api`
 * rule) - a visitor with no account can browse the dataset without a JWT
 * or an API key.
 *
 * export()/exportStatus()/downloadExport() (A2.3) are the exception: they
 * require an authenticated user (JWT or API key - either satisfies the
 * firewall's default IS_AUTHENTICATED_FULLY, which security.yaml applies
 * to `^/api/funding/export` explicitly ahead of the /api/funding
 * PUBLIC_ACCESS rule - that prefix also covers /api/funding/exports/{id},
 * since access_control matches by prefix regex).
 */
final class FundingController extends AbstractController
{
    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly ExportService $exportService,
        private readonly ExportRepository $exportRepository,
        private readonly ExportQuotaPolicy $exportQuotaPolicy,
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
        summary: 'Exporte les données de financement correspondant aux filtres (CSV ou XLSX)',
        description: 'Réservé aux utilisateurs authentifiés (JWT ou clé API), soumis à un quota quotidien par rôle (A2.3, "règle 5.2.d"). Accepte exactement les mêmes filtres que `GET /api/funding` - sans pagination : toutes les lignes correspondantes, pas une seule page. En dessous de '.ExportService::ASYNC_THRESHOLD.' lignes, le fichier est retourné immédiatement (200). Au-delà, la génération est asynchrone (règle 5.2.d) : la réponse est `202 Accepted` avec un identifiant à suivre via `GET /api/funding/exports/{id}`, et une notification est créée une fois le fichier prêt.',
        tags: ['Funding'],
        security: [['bearerAuth' => []], ['apiKeyAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: false, description: "Code ISO alpha-3 du pays (Country.isoCode)", schema: new OA\Schema(type: 'string'), example: 'SEN'),
            new OA\Parameter(name: 'sector', in: 'query', required: false, description: 'Identifiant du secteur (Sector.id)', schema: new OA\Schema(type: 'integer'), example: 1),
            new OA\Parameter(name: 'year', in: 'query', required: false, description: "Année du financement", schema: new OA\Schema(type: 'integer'), example: 2025),
            new OA\Parameter(name: 'fundingType', in: 'query', required: false, description: 'public, private ou multilateral', schema: new OA\Schema(type: 'string', enum: ['public', 'private', 'multilateral']), example: 'public'),
            new OA\Parameter(name: 'periodStart', in: 'query', required: false, description: 'Date de collecte minimale (incluse), format YYYY-MM-DD', schema: new OA\Schema(type: 'string', format: 'date'), example: '2022-01-01'),
            new OA\Parameter(name: 'periodEnd', in: 'query', required: false, description: 'Date de collecte maximale (incluse), format YYYY-MM-DD', schema: new OA\Schema(type: 'string', format: 'date'), example: '2025-12-31'),
            new OA\Parameter(name: 'format', in: 'query', required: false, description: 'Format du fichier exporté', schema: new OA\Schema(type: 'string', enum: ['csv', 'xlsx'], default: 'csv'), example: 'csv'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fichier en pièce jointe (sous le seuil asynchrone)', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(
                response: 202,
                description: 'Volume au-delà du seuil : génération asynchrone démarrée',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'pending'),
                        new OA\Property(property: 'exportId', type: 'integer', example: 12),
                        new OA\Property(property: 'message', type: 'string', example: 'Export volumineux (1080 lignes) : génération en cours, vous recevrez une notification.'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Paramètre de filtre ou de format invalide'),
            new OA\Response(response: 401, description: 'Authentification requise (JWT ou clé API)'),
            new OA\Response(response: 429, description: 'Quota d\'export quotidien dépassé pour votre rôle'),
        ]
    )]
    public function export(Request $request): Response
    {
        $format = FundingExportFormat::fromQuery($request->query);
        $criteria = FundingSearchCriteria::fromQuery($request->query);

        $this->exportQuotaPolicy->consume($this->currentUser());

        $count = $this->exportService->countMatching($criteria);

        if ($count > ExportService::ASYNC_THRESHOLD) {
            $export = $this->exportService->requestAsyncExport($this->currentUser(), $format, (string) $request->getQueryString());

            return $this->json([
                'status' => $export->getStatus()->value,
                'exportId' => $export->getId(),
                'message' => \sprintf('Export volumineux (%d lignes) : génération en cours, vous recevrez une notification.', $count),
            ], Response::HTTP_ACCEPTED);
        }

        $content = $this->exportService->generateContent($criteria, $format);

        $response = new Response($content);
        $response->headers->set('Content-Type', $format->contentType());
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            \sprintf('funding-export-%s.%s', (new \DateTimeImmutable())->format('Y-m-d-His'), $format->value)
        ));

        return $response;
    }

    #[Route('/api/funding/exports/{id}', name: 'api_funding_export_status', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        summary: 'Statut d\'un export asynchrone',
        description: 'Réservé au propriétaire de l\'export (JWT ou clé API).',
        tags: ['Funding'],
        security: [['bearerAuth' => []], ['apiKeyAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'État de l\'export',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 12),
                        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'processing', 'ready', 'failed'], example: 'ready'),
                        new OA\Property(property: 'format', type: 'string', example: 'csv'),
                        new OA\Property(property: 'rowCount', type: 'integer', nullable: true, example: 1080),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'completedAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'downloadUrl', type: 'string', nullable: true, example: '/api/funding/exports/12/download'),
                        new OA\Property(property: 'errorMessage', type: 'string', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Authentification requise'),
            new OA\Response(response: 404, description: 'Export introuvable (inexistant ou appartenant à un autre utilisateur)'),
        ]
    )]
    public function exportStatus(int $id): JsonResponse
    {
        $export = $this->exportRepository->findOneForUser($id, $this->currentUser());
        if (null === $export) {
            throw $this->createNotFoundException('Export not found.');
        }

        return $this->json([
            'id' => $export->getId(),
            'status' => $export->getStatus()->value,
            'format' => $export->getFormat()->value,
            'rowCount' => $export->getRowCount(),
            'createdAt' => $export->getCreatedAt()->format(\DATE_ATOM),
            'completedAt' => $export->getCompletedAt()?->format(\DATE_ATOM),
            'downloadUrl' => ExportStatus::Ready === $export->getStatus() ? '/api/funding/exports/'.$export->getId().'/download' : null,
            'errorMessage' => $export->getErrorMessage(),
        ]);
    }

    #[Route('/api/funding/exports/{id}/download', name: 'api_funding_export_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        summary: 'Télécharge le fichier d\'un export asynchrone terminé',
        description: 'Réservé au propriétaire de l\'export. 404 tant que le statut n\'est pas "ready".',
        tags: ['Funding'],
        security: [['bearerAuth' => []], ['apiKeyAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Fichier en pièce jointe'),
            new OA\Response(response: 401, description: 'Authentification requise'),
            new OA\Response(response: 404, description: 'Export introuvable, appartenant à un autre utilisateur, ou pas encore prêt'),
        ]
    )]
    public function downloadExport(int $id): BinaryFileResponse
    {
        $export = $this->exportRepository->findOneForUser($id, $this->currentUser());
        if (null === $export || ExportStatus::Ready !== $export->getStatus()) {
            throw $this->createNotFoundException('Export not found or not ready.');
        }

        $path = $this->exportService->absolutePathFor($export);
        if (null === $path || !is_file($path)) {
            throw $this->createNotFoundException('Export file is missing.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $export->getFormat()->contentType());
        $response->setContentDisposition(
            'attachment',
            \sprintf('funding-export-%s.%s', $export->getCreatedAt()->format('Y-m-d-His'), $export->getFormat()->value)
        );

        return $response;
    }

    private function currentUser(): \App\Entity\User
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        return $user;
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
