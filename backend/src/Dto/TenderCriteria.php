<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Enum\TenderCategory;
use App\Entity\Enum\TenderStatus;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Filtres validés, optionnels et cumulables pour GET /api/tenders (A3.13) -
 * même forme que App\Dto\OpportunityCriteria (C1.8), le DTO jumeau pour
 * l'autre radar. `status` n'est jamais une colonne stockée non plus - voir
 * App\Entity\Enum\TenderStatus et App\Service\TenderService.
 */
final readonly class TenderCriteria
{
    private function __construct(
        public ?int $sectorId,
        public ?TenderCategory $category,
        public ?TenderStatus $status,
        public ?string $searchTerm,
    ) {
    }

    public static function fromQuery(InputBag $query): self
    {
        return new self(
            self::parseSectorId($query),
            self::parseCategory($query),
            self::parseStatus($query),
            self::parseSearchTerm($query),
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

    private static function parseCategory(InputBag $query): ?TenderCategory
    {
        $raw = $query->get('category');
        if (null === $raw || '' === $raw) {
            return null;
        }

        $category = TenderCategory::tryFrom((string) $raw);
        if (null === $category) {
            $allowed = implode(', ', array_map(static fn (TenderCategory $case) => $case->value, TenderCategory::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "category": must be one of %s.', $allowed));
        }

        return $category;
    }

    private static function parseStatus(InputBag $query): ?TenderStatus
    {
        $raw = $query->get('status');
        if (null === $raw || '' === $raw) {
            return null;
        }

        $status = TenderStatus::tryFrom((string) $raw);
        if (null === $status) {
            $allowed = implode(', ', array_map(static fn (TenderStatus $case) => $case->value, TenderStatus::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "status": must be one of %s.', $allowed));
        }

        return $status;
    }

    private static function parseSearchTerm(InputBag $query): ?string
    {
        $raw = trim((string) $query->get('search', ''));

        return '' === $raw ? null : $raw;
    }

    /**
     * Same safe LIKE-pattern escaping as OpportunityCriteria::countryLikePattern().
     */
    public function searchLikePattern(): ?string
    {
        if (null === $this->searchTerm) {
            return null;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->searchTerm);

        return '%'.mb_strtolower($escaped).'%';
    }
}
