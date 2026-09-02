<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A3.7: partial index on `funding.is_current` - every read query added by
 * the point-36 fix (`FundingController`, and all three
 * `AnalyticsService`/`FundingRepository` aggregate queries -
 * `findFinancingTrendsAggregate()`/`findSectorDistributionAggregate()`/
 * `findCountryDistributionAggregate()`) filters `WHERE is_current = true`,
 * but no index existed on that column alone (only the composite indexes
 * on country_id/sector_id/year/collection_date, and the dedup unique
 * constraint that only helps when country/sector/year are ALSO pinned).
 *
 * Measured live before adding this (A3.7 load-test report): at today's
 * 1,080-row table, `EXPLAIN ANALYZE` on the sector-distribution aggregate
 * already showed a sub-millisecond `Seq Scan ... Filter: is_current`
 * (0.563ms) - this index changes nothing measurable *today*. It's added
 * proactively, not reactively: SCD2 historization (point 36 in the
 * README) means the *non-current* row count grows independently of - and
 * potentially much faster than - the current-row count every time the
 * Volet B pipeline re-runs against already-seen data, so a query that
 * scans the whole table today will scan an increasingly historized table
 * tomorrow. A partial index (`WHERE is_current = true`, same convention
 * as the existing `uniq_funding_dedup_key_current`/
 * `uniq_emission_dedup_key_current`) stays proportional to the current-
 * row count only, regardless of how large the historized tail grows.
 */
final class Version20260902133208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A3.7: partial index on funding.is_current (proactive - SCD2 historization grows the non-current tail independently of current-row count)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_funding_is_current ON funding (is_current) WHERE is_current = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_funding_is_current');
    }
}
