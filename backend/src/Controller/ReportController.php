<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ReportSearchCriteria;
use App\Entity\Report;
use App\Repository\ReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public read-only reports section (A2.13 - cahier des charges 5.2.k):
 * filters are optional and cumulable, same PUBLIC_ACCESS reasoning as
 * App\Controller\FundingController (A2.1) - reports.html has no login
 * requirement. Only App\Entity\Enum\ReportStatus::Published reports are
 * ever listed or downloadable; a Draft is a work in progress, not meant
 * to be publicly visible (see App\Repository\ReportRepository).
 *
 * download() is the "téléchargement PDF tracké" livrable: every successful
 * download increments Report::downloadCount before streaming the file -
 * the plan's "compteur" is that count, surfaced back in the list response
 * so the frontend can display it per report.
 */
final class ReportController extends AbstractController
{
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $reportStorageDir,
    ) {
    }

    #[Route('/api/reports', name: 'api_reports_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Liste paginée et filtrable des rapports publiés',
        description: 'Endpoint public (aucune authentification requise). Seuls les rapports au statut "published" apparaissent - les brouillons restent invisibles. Tous les filtres sont optionnels et cumulables.',
        tags: ['Reports'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Type de rapport exact (ex: "Annual Report", "Regional Report", "Country Report", "Sector Report")', schema: new OA\Schema(type: 'string'), example: 'Country Report'),
            new OA\Parameter(name: 'country', in: 'query', required: false, description: 'Code ISO alpha-3 du pays (Country.isoCode)', schema: new OA\Schema(type: 'string'), example: 'SEN'),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Numéro de page (défaut : 1)', schema: new OA\Schema(type: 'integer', default: 1), example: 1),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Taille de page (défaut : 12, plafond : 100)', schema: new OA\Schema(type: 'integer', default: 12, maximum: 100), example: 12),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste paginée des rapports publiés correspondant aux filtres',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'title', type: 'string', example: '2025 Global Climate Finance Overview'),
                                    new OA\Property(property: 'type', type: 'string', example: 'Annual Report'),
                                    new OA\Property(property: 'country', properties: [
                                        new OA\Property(property: 'name', type: 'string', example: 'Senegal'),
                                        new OA\Property(property: 'isoCode', type: 'string', example: 'SEN'),
                                    ], type: 'object', nullable: true),
                                    new OA\Property(property: 'region', type: 'string', example: "Afrique de l'Ouest", nullable: true),
                                    new OA\Property(property: 'publicationDate', type: 'string', format: 'date', example: '2026-02-15', nullable: true),
                                    new OA\Property(property: 'downloadCount', type: 'integer', example: 42),
                                    new OA\Property(property: 'downloadUrl', type: 'string', example: '/api/reports/1/download'),
                                ]
                            )
                        ),
                        new OA\Property(property: 'meta', properties: [
                            new OA\Property(property: 'page', type: 'integer', example: 1),
                            new OA\Property(property: 'limit', type: 'integer', example: 12),
                            new OA\Property(property: 'total', type: 'integer', example: 5),
                            new OA\Property(property: 'totalPages', type: 'integer', example: 1),
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
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid value for parameter "page": must be a positive integer.'),
                    ]
                )
            ),
        ]
    )]
    public function list(Request $request): JsonResponse
    {
        $criteria = ReportSearchCriteria::fromQuery($request->query);

        $items = $this->reportRepository->findPublished($criteria);
        $total = $this->reportRepository->countPublished($criteria);

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

    #[Route('/api/reports/{id}/download', name: 'api_reports_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        summary: 'Télécharge le PDF d\'un rapport publié (comptabilisé)',
        description: 'Endpoint public. Incrémente Report.downloadCount avant de renvoyer le fichier - c\'est le compteur affiché par GET /api/reports. 404 si le rapport n\'existe pas, n\'est pas publié, ou si le fichier PDF est absent du stockage.',
        tags: ['Reports'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 1),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fichier PDF en pièce jointe', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Rapport introuvable, non publié, ou fichier manquant'),
        ]
    )]
    public function download(int $id): BinaryFileResponse
    {
        $report = $this->reportRepository->findOnePublished($id);
        if (null === $report) {
            throw $this->createNotFoundException('Report not found.');
        }

        $path = $this->reportStorageDir.'/'.$report->getPdfFile();
        if (!is_file($path)) {
            throw $this->createNotFoundException('Report file is missing.');
        }

        $report->incrementDownloadCount();
        $this->entityManager->flush();

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition('attachment', basename($report->getPdfFile()));

        return $response;
    }

    /**
     * @return array{
     *     id: int|null,
     *     title: string,
     *     type: string,
     *     country: array{name: string, isoCode: string}|null,
     *     region: string|null,
     *     publicationDate: string|null,
     *     downloadCount: int,
     *     downloadUrl: string,
     * }
     */
    private static function toListItem(Report $report): array
    {
        $country = $report->getCountry();

        return [
            'id' => $report->getId(),
            'title' => $report->getTitle(),
            'type' => $report->getType(),
            'country' => null !== $country ? [
                'name' => $country->getName(),
                'isoCode' => $country->getIsoCode(),
            ] : null,
            'region' => $report->getRegion(),
            'publicationDate' => $report->getPublicationDate()?->format('Y-m-d'),
            'downloadCount' => $report->getDownloadCount(),
            'downloadUrl' => '/api/reports/'.$report->getId().'/download',
        ];
    }
}
