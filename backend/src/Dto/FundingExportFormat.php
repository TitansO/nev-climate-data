<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * File format accepted by GET /api/funding/export (A2.3). The plan
 * (row A2.3: "module d'export (CSV, Excel...)") confirms both formats are
 * required - CSV was implemented first without plan access, XLSX added
 * once the plan text was available (see the A2.9/A2.10 report's follow-up
 * note on this gap). PDF is not listed anywhere and stays unimplemented.
 */
enum FundingExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

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

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=utf-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
