<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 2026-09-28 : fusionne les stades `financed`/`rejected` de `project.
 * pipeline_stage` en un seul stade `decision`, et introduit un nouveau
 * stade `execution` pour les projets financés déplacés en suivi
 * post-financement (voir App\Entity\Enum\ProjectPipelineStage et les deux
 * nouvelles énumérations ProjectDecisionOutcome/ProjectExecutionStatus).
 * Deux nouvelles colonnes nullables portent l'issue/l'état, affichés comme
 * étiquette sur la carte du Kanban plutôt que comme colonne séparée -
 * jamais de valeur inventée : les lignes existantes sont remappées
 * automatiquement (financed -> execution + decision_outcome=financed +
 * execution_status=in_progress ; rejected -> decision +
 * decision_outcome=rejected), toute autre ligne reste inchangée.
 */
final class Version20260928140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '2026-09-28: fusionne financed/rejected en decision, ajoute le stade execution + decision_outcome/execution_status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD decision_outcome VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD execution_status VARCHAR(20) DEFAULT NULL');

        $this->addSql("UPDATE project SET decision_outcome = 'financed', execution_status = 'in_progress', pipeline_stage = 'execution' WHERE pipeline_stage = 'financed'");
        $this->addSql("UPDATE project SET decision_outcome = 'rejected', pipeline_stage = 'decision' WHERE pipeline_stage = 'rejected'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE project SET pipeline_stage = 'financed' WHERE pipeline_stage = 'execution' AND decision_outcome = 'financed'");
        $this->addSql("UPDATE project SET pipeline_stage = 'rejected' WHERE pipeline_stage = 'decision' AND decision_outcome = 'rejected'");

        $this->addSql('ALTER TABLE project DROP decision_outcome');
        $this->addSql('ALTER TABLE project DROP execution_status');
    }
}
