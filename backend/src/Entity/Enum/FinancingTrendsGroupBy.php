<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * C1.6: dimension GET /api/analytics/financing-trends?groupBy=... breaks
 * its yearly totals down by. Not a database column - this only exists to
 * validate the query parameter and select which repository aggregate
 * App\Service\AnalyticsService::getFinancingTrends() runs.
 *
 * `FundingType` reproduces the endpoint's pre-C1.6 behavior (public/
 * private/multilateral) - the only difference is the response shape (a
 * generic `groups` object instead of hardcoded public/private/multilateral
 * keys), used only when `groupBy` is explicitly passed; omitting the
 * parameter entirely keeps the original flat shape unchanged.
 */
enum FinancingTrendsGroupBy: string
{
    case Sector = 'sector';
    case Instrument = 'instrument';
    case Country = 'country';
    case FundingType = 'fundingType';
}
