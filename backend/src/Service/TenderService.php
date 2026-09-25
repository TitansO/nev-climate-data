<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TenderCriteria;
use App\Entity\Enum\TenderStatus;
use App\Entity\Tender;
use App\Repository\TenderRepository;

/**
 * A3.13 : "Tender Radar" - GET /api/tenders. Même structure que
 * App\Service\OpportunityService (C1.8) - calcule TenderStatus par
 * rapport à "maintenant" (jamais une colonne stockée), puis applique
 * $criteria->status en PHP. Pas de cache (table manuellement saisie,
 * volume minuscule) - même raisonnement que OpportunityService.
 */
final class TenderService
{
    public function __construct(
        private readonly TenderRepository $tenderRepository,
    ) {
    }

    /**
     * @return list<array{
     *     id: int, title: string, issuingOrganization: string, referenceNumber: ?string,
     *     category: string, sector: ?string, description: string, eligibilityCriteria: string,
     *     estimatedValue: ?float, publishedAt: string, submissionDeadline: string,
     *     status: string, documentsUrl: ?string, contactEmail: ?string
     * }>
     */
    public function list(TenderCriteria $criteria): array
    {
        $now = new \DateTimeImmutable();

        $results = array_map(
            static fn (Tender $tender): array => self::toArray($tender, self::statusOf($tender, $now)),
            $this->tenderRepository->findByCriteria($criteria),
        );

        if (null !== $criteria->status) {
            $results = array_values(array_filter($results, static fn (array $row): bool => $row['status'] === $criteria->status->value));
        }

        return $results;
    }

    public static function statusOf(Tender $tender, \DateTimeImmutable $now): TenderStatus
    {
        if ($tender->getPublishedAt() > $now) {
            return TenderStatus::Upcoming;
        }

        if ($tender->getSubmissionDeadline() < $now) {
            return TenderStatus::Closed;
        }

        return TenderStatus::Open;
    }

    /**
     * @return array{
     *     id: int, title: string, issuingOrganization: string, referenceNumber: ?string,
     *     category: string, sector: ?string, description: string, eligibilityCriteria: string,
     *     estimatedValue: ?float, publishedAt: string, submissionDeadline: string,
     *     status: string, documentsUrl: ?string, contactEmail: ?string
     * }
     */
    private static function toArray(Tender $tender, TenderStatus $status): array
    {
        return [
            'id' => $tender->getId(),
            'title' => $tender->getTitle(),
            'issuingOrganization' => $tender->getIssuingOrganization(),
            'referenceNumber' => $tender->getReferenceNumber(),
            'category' => $tender->getCategory()->value,
            'sector' => $tender->getSector()?->getName(),
            'description' => $tender->getDescription(),
            'eligibilityCriteria' => $tender->getEligibilityCriteria(),
            'estimatedValue' => null !== $tender->getEstimatedValue() ? (float) $tender->getEstimatedValue() : null,
            'publishedAt' => $tender->getPublishedAt()->format('Y-m-d'),
            'submissionDeadline' => $tender->getSubmissionDeadline()->format('Y-m-d'),
            'status' => $status->value,
            'documentsUrl' => $tender->getDocumentsUrl(),
            'contactEmail' => $tender->getContactEmail(),
        ];
    }
}
