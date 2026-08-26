<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * File format accepted by GET /api/funding/export (A2.3). Only `csv` is
 * implemented - nothing in the project's plan/docs confirms an XLSX or PDF
 * requirement (see the A2.3/A2.4 implementation report), so this stays a
 * single-case enum rather than guessing at formats to support. Modeled as
 * an enum (not a raw string check) so adding a format later is a one-case
 * addition here, not a new parsing path.
 */
enum FundingExportFormat: string
{
    case Csv = 'csv';

    public static function fromQuery(InputBag $query): self
    {
        $raw = $query->get('format', self::Csv->value);

        $format = self::tryFrom((string) $raw);
        if (null === $format) {
            $allowed = implode(', ', array_map(static fn (self $case) => $case->value, self::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "format": must be one of %s.', $allowed));
        }

        return $format;
    }
}
