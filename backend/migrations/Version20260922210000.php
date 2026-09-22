<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * C3.8 : `Score::$previousValue` (App\Entity\Score) - permet à
 * AlertEvaluationService de détecter une dégradation de score sans
 * réintroduire une historisation complète (voir le docblock de `Score`).
 */
final class Version20260922210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'C3.8 : Score::$previousValue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE score ADD previous_value DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE score DROP previous_value');
    }
}
