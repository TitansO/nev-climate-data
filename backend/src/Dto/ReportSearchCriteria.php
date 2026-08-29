<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Validated, immutable representation of the query parameters accepted by
 * GET /api/reports (A2.13, cahier des charges 5.2.k). Built once via
 * {@see self::fromQuery()} and reused by both
 * {@see \App\Repository\ReportRepository::findPublished()} and
 * {@see \App\Repository\ReportRepository::countPublished()}, so the two
 * queries can never drift out of sync on which filters they apply - same
 * pattern as App\Dto\FundingSearchCriteria (A2.1).
 *
 * Every parameter is optional and cumulable. Invalid input throws
 * BadRequestHttpException (caught by App\EventListener\ApiExceptionListener
 * and rendered as {"code":400,"message":"..."}), never a 500 or an HTML page.
 */
final readonly class ReportSearchCriteria
{
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_LIMIT = 12;
    public const MAX_LIMIT = 100;

    private function __construct(
        public ?string $type,
        public ?string $countryIsoCode,
        public int $page,
        public int $limit,
    ) {
    }

    public static function fromQuery(InputBag $query): self
    {
        return new self(
            self::parseType($query),
            self::parseCountry($query),
            self::parsePage($query),
            self::parseLimit($query),
        );
    }

    private static function parseType(InputBag $query): ?string
    {
        $raw = $query->get('type');
        if (null === $raw || '' === $raw) {
            return null;
        }

        return trim((string) $raw);
    }

    private static function parseCountry(InputBag $query): ?string
    {
        $raw = $query->get('country');
        if (null === $raw || '' === $raw) {
            return null;
        }

        return strtoupper(trim((string) $raw));
    }

    private static function parsePage(InputBag $query): int
    {
        $raw = $query->get('page');
        if (null === $raw || '' === $raw) {
            return self::DEFAULT_PAGE;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw < 1) {
            throw new BadRequestHttpException('Invalid value for parameter "page": must be a positive integer.');
        }

        return (int) $raw;
    }

    private static function parseLimit(InputBag $query): int
    {
        $raw = $query->get('limit');
        if (null === $raw || '' === $raw) {
            return self::DEFAULT_LIMIT;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw < 1) {
            throw new BadRequestHttpException('Invalid value for parameter "limit": must be a positive integer.');
        }

        return min((int) $raw, self::MAX_LIMIT);
    }
}
