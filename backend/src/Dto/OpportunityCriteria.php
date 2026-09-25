<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\OpportunityStatus;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Validated, optional and cumulable filters for GET /api/opportunities
 * (C1.8) - same validate-once-and-reuse shape as the rest of this
 * namespace (FundingSearchCriteria, SearchQuery, ...).
 *
 * `country` is deliberately a free-text substring match against
 * `Opportunity::eligibilityCriteria`, not a structured Country reference:
 * every seeded opportunity is genuinely regional/multi-country ("tous
 * pays NEV", "les 11 pays du G5 Sahel", "petits États insulaires...") -
 * OpportunityFixtures's own docblock explains why there is no single
 * eligible country to store per row. Forcing a single Country FK would
 * misrepresent that data, not just be unpopulated - the free-text field
 * is the actual, honest source of truth for eligibility today.
 *
 * `status` is never a stored column either - see App\Entity\Enum\
 * OpportunityStatus and App\Service\OpportunityService, which compute it
 * from applicationWindowOpensAt/ClosesAt against "now" on every request.
 */
final readonly class OpportunityCriteria
{
    private function __construct(
        public ?int $sectorId,
        public ?string $countryTerm,
        public ?OpportunityStatus $status,
        public ?FundingInstrument $instrument,
    ) {
    }

    public static function fromQuery(InputBag $query): self
    {
        return new self(
            self::parseSectorId($query),
            self::parseCountryTerm($query),
            self::parseStatus($query),
            self::parseInstrument($query),
        );
    }

    private static function parseSectorId(InputBag $query): ?int
    {
        $raw = $query->get('sector');
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw < 1) {
            throw new BadRequestHttpException('Invalid value for parameter "sector": must be a positive integer.');
        }

        return (int) $raw;
    }

    private static function parseCountryTerm(InputBag $query): ?string
    {
        $raw = trim((string) $query->get('country', ''));

        return '' === $raw ? null : $raw;
    }

    private static function parseStatus(InputBag $query): ?OpportunityStatus
    {
        $raw = $query->get('status');
        if (null === $raw || '' === $raw) {
            return null;
        }

        $status = OpportunityStatus::tryFrom((string) $raw);
        if (null === $status) {
            $allowed = implode(', ', array_map(static fn (OpportunityStatus $case) => $case->value, OpportunityStatus::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "status": must be one of %s.', $allowed));
        }

        return $status;
    }

    private static function parseInstrument(InputBag $query): ?FundingInstrument
    {
        $raw = $query->get('instrument');
        if (null === $raw || '' === $raw) {
            return null;
        }

        $instrument = FundingInstrument::tryFrom((string) $raw);
        if (null === $instrument) {
            $allowed = implode(', ', array_map(static fn (FundingInstrument $case) => $case->value, FundingInstrument::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "instrument": must be one of %s.', $allowed));
        }

        return $instrument;
    }

    /**
     * Same safe LIKE-pattern escaping as SearchQuery::likePattern() - "%"/"_"
     * in $countryTerm must never act as a SQL wildcard.
     */
    public function countryLikePattern(): ?string
    {
        if (null === $this->countryTerm) {
            return null;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->countryTerm);

        return '%'.mb_strtolower($escaped).'%';
    }
}
