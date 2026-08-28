<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260828024203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B1.1: partial unique index on the funding dedup key, scoped to current rows only (is_current = true), plus a unique constraint on source.name';
    }

    public function up(Schema $schema): void
    {
        // Partial index (WHERE is_current = true), not a flat unique constraint - see the
        // comment above #[ORM\UniqueConstraint] on Funding.php for why: historization keeps
        // multiple rows sharing this same tuple over time, only one of them current.
        $this->addSql('CREATE UNIQUE INDEX uniq_funding_dedup_key_current ON funding (source_id, country_id, sector_id, year, funding_type) WHERE is_current = true');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5F8A7F735E237E06 ON source (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_funding_dedup_key_current');
        $this->addSql('DROP INDEX UNIQ_5F8A7F735E237E06');
    }
}
