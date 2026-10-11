<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit Prompt Maitre #3 (point 5, 2026-10-11) : ajoute
 * `funding.external_reference` (nullable) - identifiant du projet chez la
 * source (ex. identifiant d'activite IATI), deja produit par chaque
 * connecteur pipeline sous `project_id` et deja persiste dans
 * `funding_project_contribution`, mais jamais reporte sur `funding`
 * elle-meme. Objectif : permettre de verifier manuellement un doublon
 * cross-source suspecte (ex. meme pays/secteur/annee/montant proche
 * rapporte par AfDB et World Bank - 13 cas trouves lors de l'audit en
 * direct sur les 3996 lignes reelles) au lieu de deviner sur la seule
 * proximite de montant. Aucune donnee existante modifiee ou supprimee.
 */
final class Version20261011130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Audit Prompt Maitre #3 (point 5) : ajoute funding.external_reference (nullable) pour la tracabilite cross-source";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE funding ADD external_reference VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE funding DROP external_reference');
    }
}
