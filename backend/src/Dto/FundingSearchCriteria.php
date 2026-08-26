<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Enum\FundingType;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Validated, immutable representation of the query parameters accepted by
 * GET /api/funding (cahier des charges 5.2.c). Built once via
 * {@see self::fromQuery()} and reused by both
 * {@see \App\Repository\FundingRepository::findByCriteria()} and
 * {@see \App\Repository\FundingRepository::countByCriteria()}, so the two
 * queries can never drift out of sync on which filters they apply.
 *
 * Every parameter is optional and cumulable. Invalid input throws
 * BadRequestHttpException (caught by App\EventListener\ApiExceptionListener
 * and rendered as {"code":400,"message":"..."}), never a 500 or an HTML page.
 */
final readonly class FundingSearchCriteria
{
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_LIMIT = 20;

    /**
     * Performance guard (cahier des charges 5.2.c): a client asking for more
     * than this is silently capped, not rejected — asking for "too much" is
     * not an invalid request the way a negative page number is.
     */
    public const MAX_LIMIT = 100;

    private function __construct(
        public ?string $countryIsoCode,
        public ?int $sectorId,
        public ?int $year,
        public ?FundingType $fundingType,
        public ?\DateTimeImmutable $periodStart,
        public ?\DateTimeImmutable $periodEnd,
        public int $page,
        public int $limit,
    ) {
    }

    public static function fromQuery(InputBag $query): self
    {
        $periodStart = self::parseDate($query, 'periodStart');
        $periodEnd = self::parseDate($query, 'periodEnd');

        if (null !== $periodStart && null !== $periodEnd && $periodStart > $periodEnd) {
            throw new BadRequestHttpException('"periodStart" must not be after "periodEnd".');
        }

        return new self(
            self::parseCountry($query),
            self::parsePositiveInt($query, 'sector'),
            self::parsePositiveInt($query, 'year', allowAnyPositive: true),
            self::parseFundingType($query),
            $periodStart,
            $periodEnd,
            self::parsePage($query),
            self::parseLimit($query),
        );
    }

    private static function parseCountry(InputBag $query): ?string
    {
        $raw = $query->get('country');
        if (null === $raw || '' === $raw) {
            return null;
        }

        return strtoupper(trim((string) $raw));
    }

    private static function parsePositiveInt(InputBag $query, string $name, bool $allowAnyPositive = false): ?int
    {
        $raw = $query->get($name);
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw) {
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "%s": must be an integer.', $name));
        }

        $value = (int) $raw;
        if ($value < 1 && !$allowAnyPositive) {
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "%s": must be a positive integer.', $name));
        }

        return $value;
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

    private static function parseDate(InputBag $query, string $name): ?\DateTimeImmutable
    {
        $raw = $query->get($name);
        if (null === $raw || '' === $raw) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $raw);
        $errors = \DateTimeImmutable::getLastErrors();

        if (false === $date || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "%s": expected format YYYY-MM-DD.', $name));
        }

        return $date;
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
