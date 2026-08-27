<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A2.8 accent-insensitive search: enables PostgreSQL's "unaccent" extension
 * so App\Doctrine\DQL\UnaccentFunction (registered in
 * config/packages/doctrine.yaml) can strip diacritics server-side in the
 * global search queries (CountryRepository, SectorRepository,
 * SourceRepository, ReportRepository).
 *
 * "unaccent" ships in PostgreSQL's contrib module and is on the
 * "trusted extension" allowlist since PG13, so this does not require
 * superuser - any role with CREATE on the database (the app's own DB user)
 * can install it. Not entity-mapped, so this is a plain SQL migration
 * rather than something doctrine:migrations:diff would ever generate on
 * its own.
 */
final class Version20260827160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable the PostgreSQL unaccent extension for accent-insensitive search (A2.8)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS unaccent');
    }
}
