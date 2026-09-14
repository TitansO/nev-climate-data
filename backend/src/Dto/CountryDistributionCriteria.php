<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\FundingType;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * C1.2: optional filters accepted by GET /api/analytics/country-distribution
 * (sector/instrument/year/fundingType) - same validate-once-and-reuse shape
 * as {@see FundingSearchCriteria} for GET /api/funding, but without the
 * pagination/date-range/country fields that endpoint needs and this one
 * doesn't (a per-country breakdown filtered *by* country would defeat its
 * own purpose).
 *
 * Every field is optional and cumulable; an invalid value throws
 * BadRequestHttpException, rendered by App\EventListener\ApiExceptionListener
 * as {"code":400,"message":"..."}. An all-null instance behaves exactly like
 * the endpoint did before C1.2 (no WHERE clause added, same cache key).
 */
final readonly class CountryDistributionCriteria
{
    private function __construct(
        public ?int $sectorId,
        public ?FundingInstrument $instrument,
        public ?int $year,
        public ?FundingType $fundingType,
    ) {
    }

    public static function fromQuery(InputBag $query): self
    {
        return new self(
            self::parseSectorId($query),
            self::parseInstrument($query),
            self::parseYear($query),
            self::parseFundingType($query),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->sectorId && null === $this->instrument && null === $this->year && null === $this->fundingType;
    }

    /**
     * Stable cache-key fragment for this exact filter combination - two
     * requests with the same filters (in any query-string order) must
     * always resolve to the same Redis key, so each combination gets its
     * own cached aggregate instead of colliding with, or being starved by,
     * another one.
     */
    public function toCacheKeyFragment(): string
    {
        return implode(',', [
            'sector='.($this->sectorId ?? ''),
            'instrument='.($this->instrument?->value ?? ''),
            'year='.($this->year ?? ''),
            'fundingType='.($this->fundingType?->value ?? ''),
        ]);
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

    private static function parseYear(InputBag $query): ?int
    {
        $raw = $query->get('year');
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw) {
            throw new BadRequestHttpException('Invalid value for parameter "year": must be an integer.');
        }

        return (int) $raw;
    }

    private static function parseFundingType(InputBag $query): ?FundingType
    {
        $raw = $query->get('fundingType');
        if (null === $raw || '' === $raw) {
            return null;
        }

        $fundingType = FundingType::tryFrom((string) $raw);
        if (null === $fundingType) {
            $allowed = implode(', ', array_map(static fn (FundingType $case) => $case->value, FundingType::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "fundingType": must be one of %s.', $allowed));
        }

        return $fundingType;
    }
}
