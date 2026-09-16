<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Enum\FundingInstrument;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Validated, optional and cumulable filters for
 * GET /api/analytics/funding-flows (C1.5) - same validate-once-and-reuse
 * shape as the rest of this namespace.
 */
final readonly class FundingFlowCriteria
{
    public const DEFAULT_TOP_N = 8;
    public const MIN_TOP_N = 1;
    public const MAX_TOP_N = 25;

    private function __construct(
        public ?FundingInstrument $instrument,
        public ?int $year,
        public int $topN,
    ) {
    }

    public static function fromQuery(InputBag $query): self
    {
        return new self(
            self::parseInstrument($query),
            self::parseYear($query),
            self::parseTopN($query),
        );
    }

    /**
     * Stable cache-key fragment for this exact filter combination - "|",
     * not ":", since Symfony's PSR-6 cache key validator rejects a literal
     * ":" (confirmed live during C1.2 - see AnalyticsService's own comment).
     */
    public function toCacheKeyFragment(): string
    {
        return implode(',', [
            'instrument='.($this->instrument?->value ?? ''),
            'year='.($this->year ?? ''),
            'topN='.$this->topN,
        ]);
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

    private static function parseTopN(InputBag $query): int
    {
        $raw = $query->get('topN');
        if (null === $raw || '' === $raw) {
            return self::DEFAULT_TOP_N;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw) {
            throw new BadRequestHttpException('Invalid value for parameter "topN": must be an integer.');
        }

        $value = (int) $raw;
        if ($value < self::MIN_TOP_N || $value > self::MAX_TOP_N) {
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "topN": must be between %d and %d.', self::MIN_TOP_N, self::MAX_TOP_N));
        }

        return $value;
    }
}
