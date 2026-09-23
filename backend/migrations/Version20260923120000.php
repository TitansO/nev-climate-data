<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit pre-production 2026-09-23 (P3) : `idx_funding_is_current`
 * (Version20260902133208) couvre `is_current` seul, mais aucune requete
 * du projet ne filtre jamais `is_current` sans filtrer aussi
 * `validation_status = 'validated'` juste a cote
 * (`FundingRepository::onlyValidated()`, appelee sur la quasi-totalite
 * des ~30 methodes qui filtrent `isCurrent = true` - verifie par
 * grep : 30 occurrences de "isCurrent = true" contre 31 de
 * "onlyValidated(" dans ce fichier). Meme constat sur `emission`
 * (`EmissionRepository`, `isCurrent = true` + `validationStatus =
 * :validated` systematiques) - qui n'a meme pas encore l'equivalent de
 * `idx_funding_is_current` a ce jour. Un index composite partiel
 * `(is_current, validation_status) WHERE is_current = true` laisse le
 * planner filtrer les deux colonnes directement depuis l'index plutot
 * que de re-filtrer validation_status ligne par ligne apres l'index
 * partiel existant.
 *
 * Meme discipline "proactive, pas reactive" que Version20260902133208 :
 * a la volumetrie actuelle (table funding: 1080 lignes), aucun gain
 * mesurable attendu (Seq Scan deja sub-milliseconde) - mais la queue de
 * lignes non-courantes issue de l'historisation SCD2 grossit
 * independamment du nombre de lignes courantes a chaque re-run du
 * pipeline Volet B, donc le cout d'un Seq Scan+Filter grossira avec le
 * temps meme si le nombre de lignes utiles (is_current=true) reste
 * stable.
 *
 * Additif, pas un remplacement : `idx_funding_is_current` reste en
 * place (une requete qui filtrerait un jour `is_current` seul, sans
 * `validation_status`, en beneficie toujours).
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Audit pre-production 2026-09-23 (P3): index composite partiel (is_current, validation_status) sur funding et emission - filtre systematiquement combine dans les deux repositories";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_funding_current_validated ON funding (is_current, validation_status) WHERE is_current = true');
        $this->addSql('CREATE INDEX idx_emission_current_validated ON emission (is_current, validation_status) WHERE is_current = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_funding_current_validated');
        $this->addSql('DROP INDEX idx_emission_current_validated');
    }
}
