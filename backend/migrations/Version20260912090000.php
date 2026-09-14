<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F1 (fondation du sprint C1-C3) : ajoute `funding.instrument`
 * (FundingInstrument - grant/loan_concessional/loan_non_concessional/
 * guarantee/equity/results_based/unknown), alimenté par les connecteurs
 * IATI (GCF/BAD) via `pipeline/processors/instrument_mapping.py`. World
 * Bank et OPEC Fund PDF ne portent aucun signal d'instrument et laissent
 * la valeur par défaut.
 *
 * `NOT NULL DEFAULT 'unknown'` backfille automatiquement les lignes déjà
 * en base (réelles et démo) sans UPDATE séparé - même mécanique que
 * `is_current BOOLEAN DEFAULT true NOT NULL` dans la migration fondatrice
 * (Version20260824013715).
 */
final class Version20260912090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "F1 : ajoute funding.instrument (FundingInstrument), defaut 'unknown' pour les lignes existantes";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE funding ADD instrument VARCHAR(25) DEFAULT 'unknown' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE funding DROP instrument');
    }
}
