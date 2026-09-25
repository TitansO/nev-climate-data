<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A3.13 (2026-09-25) : cree la table `tender` (appels d'offres / marches
 * de prestation) - jumelle de `opportunity` (F4) pour le nouveau "Tender
 * Radar", distinct du "Opportunity Radar" existant. Voir la docblock de
 * App\Entity\Tender pour la difference de fond entre les deux concepts.
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A3.13: cree la table tender (appels d offres)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tender (
            id SERIAL NOT NULL,
            sector_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            issuing_organization VARCHAR(255) NOT NULL,
            reference_number VARCHAR(100) DEFAULT NULL,
            category VARCHAR(20) NOT NULL,
            description TEXT NOT NULL,
            eligibility_criteria TEXT NOT NULL,
            estimated_value NUMERIC(15, 2) DEFAULT NULL,
            published_at DATE NOT NULL,
            submission_deadline DATE NOT NULL,
            documents_url VARCHAR(2048) DEFAULT NULL,
            contact_email VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_tender_deadline ON tender (submission_deadline)');
        $this->addSql('CREATE INDEX idx_tender_sector ON tender (sector_id)');
        $this->addSql('ALTER TABLE tender ADD CONSTRAINT FK_tender_sector FOREIGN KEY (sector_id) REFERENCES sector (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tender');
    }
}
