<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Country.currency (ISO 4217 code of the country's own national
 * currency) - backs the "Montant (devise locale)" column added to
 * data.html, see App\Reference\AfricanCurrencies for the reference data
 * and CountryFixtures for how it's populated.
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add country.currency (ISO 4217 code) for the funding table's local-currency column";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country ADD currency VARCHAR(3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country DROP currency');
    }
}
