<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normalisation typographique : remplace les tirets longs (cadratin U+2014
 * « - » et demi-cadratin U+2013 « - ») par le trait d'union ASCII « - »
 * dans toutes les colonnes de texte libre visibles dans l'application.
 *
 * Le code (fixtures, connecteurs du pipeline, libelles OpenAPI, textes du
 * frontend) a ete bascule sur le trait d'union ASCII en une passe. Sans
 * cette migration, les lignes deja en base gardent l'ancien caractere - et
 * surtout les connecteurs du Volet B (`pipeline/processors/*_validator.py`)
 * recherchent desormais `source.name = '... - IATI Datastore'` : une source
 * encore stockee avec le cadratin ne serait plus retrouvee et une source
 * doublon serait creee a la collecte suivante, detachant les ~3 000 lignes
 * `funding` reelles de leur source d'origine.
 *
 * `REPLACE` est un no-op sur les valeurs deja propres.
 *
 * `down()` est volontairement vide : la normalisation n'est pas reversible
 * (on ne sait pas quels « - » etaient d'origine des cadratins), et revenir
 * en arriere recasserait la correspondance avec le code.
 */
final class Version20260910120000 extends AbstractMigration
{
    /** @var array<int, array{0: string, 1: string}> [table, colonne] */
    private const TARGETS = [
        ['source', 'name'],
        ['report', 'title'],
        ['report', 'region'],
        ['notification', 'content'],
        ['country', 'name'],
        ['country', 'region'],
        ['sector', 'name'],
    ];

    public function getDescription(): string
    {
        return 'Normalise les tirets longs (U+2014 / U+2013) en trait d\'union ASCII dans les colonnes de texte visibles';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TARGETS as [$table, $column]) {
            $this->addSql(sprintf(
                "UPDATE %s SET %s = REPLACE(REPLACE(%s, CHR(8212), '-'), CHR(8211), '-') "
                ."WHERE %s LIKE '%%' || CHR(8212) || '%%' OR %s LIKE '%%' || CHR(8211) || '%%'",
                $table,
                $column,
                $column,
                $column,
                $column,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // Non reversible - voir le docblock de la classe.
    }
}
