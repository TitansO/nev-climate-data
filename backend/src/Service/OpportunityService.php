<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\OpportunityCriteria;
use App\Entity\Enum\OpportunityStatus;
use App\Entity\Opportunity;
use App\Repository\OpportunityRepository;

/**
 * C1.8 : "Opportunity Radar" - GET /api/opportunities. Computes each
 * opportunity's OpportunityStatus (open/upcoming/closed) against "now",
 * since that status is never a stored column (see OpportunityStatus's own
 * docblock), then applies $criteria->status as a PHP-side filter - the
 * repository already applied sector/country (real SQL columns/patterns).
 * No caching (unlike AnalyticsService): this table is a manually-seeded
 * handful of rows (F4), the same "too small to bother caching" reasoning
 * SearchService already applies to Country/Sector/Source/Report.
 */
final class OpportunityService
{
    public function __construct(
        private readonly OpportunityRepository $opportunityRepository,
    ) {
    }

    /**
     * @return list<array{
     *     id: int, title: string, sourceName: string, sector: ?string,
     *     eligibilityCriteria: string, envelopeAmount: float,
     *     applicationWindowOpensAt: ?string, applicationWindowClosesAt: ?string,
     *     status: string
     * }>
     */
    public function list(OpportunityCriteria $criteria): array
    {
        $now = new \DateTimeImmutable();

        $results = array_map(
            static fn (Opportunity $opportunity): array => self::toArray($opportunity, self::statusOf($opportunity, $now)),
            $this->opportunityRepository->findByCriteria($criteria),
        );

        if (null !== $criteria->status) {
            $results = array_values(array_filter($results, static fn (array $row): bool => $row['status'] === $criteria->status->value));
        }

        return $results;
    }

    /**
     * Public since C2.2: App\Service\ProjectMatchingService reuses this
     * exact open/upcoming/closed computation for its own "fenêtre
     * temporelle" score component - one source of truth for what "open"
     * means for an Opportunity, not two independent date comparisons that
     * could drift apart.
     */
    public static function statusOf(Opportunity $opportunity, \DateTimeImmutable $now): OpportunityStatus
    {
        $closesAt = $opportunity->getApplicationWindowClosesAt();
        if (null !== $closesAt && $closesAt < $now) {
            return OpportunityStatus::Closed;
        }

        $opensAt = $opportunity->getApplicationWindowOpensAt();
        if (null !== $opensAt && $opensAt > $now) {
            return OpportunityStatus::Upcoming;
        }

        return OpportunityStatus::Open;
    }

    /**
     * @return array{
     *     id: int, title: string, sourceName: string, sector: ?string,
     *     eligibilityCriteria: string, envelopeAmount: float,
     *     applicationWindowOpensAt: ?string, applicationWindowClosesAt: ?string,
     *     status: string
     * }
     */
    private static function toArray(Opportunity $opportunity, OpportunityStatus $status): array
    {
        return [
            'id' => $opportunity->getId(),
            'title' => $opportunity->getTitle(),
            'sourceName' => $opportunity->getSource()->getName(),
            'sector' => $opportunity->getSector()?->getName(),
            'eligibilityCriteria' => $opportunity->getEligibilityCriteria(),
            'envelopeAmount' => (float) $opportunity->getEnvelopeAmount(),
            'applicationWindowOpensAt' => $opportunity->getApplicationWindowOpensAt()?->format('Y-m-d'),
            'applicationWindowClosesAt' => $opportunity->getApplicationWindowClosesAt()?->format('Y-m-d'),
            'status' => $status->value,
        ];
    }
}
