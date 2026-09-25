<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A3.12 (2026-09-25) : enrichit `Opportunity` (C1.8, "Opportunity Radar")
 * de quatre champs jusqu'ici absents - demande explicite de l'utilisateur
 * ("maximum d'informations possibles") apres avoir constate que la fiche
 * d'un appel a projets ne portait que le texte libre d'eligibilite, sans
 * lien de candidature, documents requis, contact ni type d'instrument
 * structure. Les quatre colonnes sont nullables : le jeu de fixtures
 * existant (9 lignes, F4) et toute saisie manuelle anterieure restent
 * valides sans backfill obligatoire.
 */
final class Version20260925103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A3.12: ajoute instrument/applicationUrl/contactEmail/requiredDocuments a opportunity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE opportunity ADD instrument VARCHAR(25) DEFAULT NULL');
        $this->addSql('ALTER TABLE opportunity ADD application_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE opportunity ADD contact_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE opportunity ADD required_documents TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE opportunity DROP instrument');
        $this->addSql('ALTER TABLE opportunity DROP application_url');
        $this->addSql('ALTER TABLE opportunity DROP contact_email');
        $this->addSql('ALTER TABLE opportunity DROP required_documents');
    }
}
