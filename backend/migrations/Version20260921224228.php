<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * C2.7 : `Project::$taxonomyClass` (App\Entity\Project) - classe de
 * taxonomie verte simplifiée, renseignée par PATCH /api/projects/{id}/
 * classify.
 *
 * Nettoyée à la main après `doctrine:migrations:diff`, même bruit déjà
 * documenté sur la migration `impact_factor` (C2.5, Version20260917095153) :
 * re-normalisation de syntaxe des index partiels `funding`/`emission`
 * (faux positif, aucun changement réel) et un `down()` qui tentait de
 * recréer les schémas internes de l'extension TimescaleDB - les deux
 * retirés.
 */
final class Version20260921224228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'C2.7 : Project::$taxonomyClass';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD taxonomy_class VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP taxonomy_class');
    }
}
