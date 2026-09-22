<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * C3.3 : `Country::$population` + `Country::$vulnerabilityIndex` (App\Entity\Country) -
 * nécessaires à EquityScoreService, renseignés uniquement pour le
 * sous-ensemble de pays couvert par CountryEquityDataFixtures.
 */
final class Version20260922190933 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'C3.3 : Country::$population + Country::$vulnerabilityIndex';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country ADD population INT DEFAULT NULL');
        $this->addSql('ALTER TABLE country ADD vulnerability_index DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country DROP population');
        $this->addSql('ALTER TABLE country DROP vulnerability_index');
    }
}
