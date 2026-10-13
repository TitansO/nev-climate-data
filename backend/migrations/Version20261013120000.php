<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit 2026-10-13 : ajoute `funding.local_amount`/`local_currency`/
 * `local_exchange_rate` (tous nullable). Trouve en direct par
 * l'utilisateur : une ligne AFD affichait "8 300 000,00 EUR" sous
 * l'ancienne colonne "devise locale" - EUR n'est la devise locale
 * d'aucun pays africain beneficiaire. Cause reelle : AFD/AfDB rapportent
 * leur propre montant institutionnel (EUR/XDR, deja stocke dans
 * original_amount/original_currency, jamais touche ici), pas celui du
 * pays receveur. Ces 3 nouvelles colonnes sont TOUJOURS une conversion
 * live-rate vers la devise du PAYS beneficiaire, calculee pour toutes
 * les sources - voir pipeline/processors/funding_validator.py::
 * process_message(). Aucune donnee existante modifiee ou supprimee.
 */
final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Audit 2026-10-13 : ajoute funding.local_amount/local_currency/local_exchange_rate (nullable) - conversion vers la devise du pays beneficiaire, distincte du montant d'origine de la source";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE funding ADD local_amount NUMERIC(15, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE funding ADD local_currency VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE funding ADD local_exchange_rate NUMERIC(12, 6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE funding DROP local_amount');
        $this->addSql('ALTER TABLE funding DROP local_currency');
        $this->addSql('ALTER TABLE funding DROP local_exchange_rate');
    }
}
