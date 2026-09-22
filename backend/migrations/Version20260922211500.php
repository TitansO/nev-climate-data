<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * C3.8 : table `alert_rule` (App\Entity\AlertRule) - une ligne par
 * (utilisateur, type de règle) souhaitant être notifié d'une condition
 * (App\Entity\Enum\AlertRuleType), évaluée par App\Service\
 * AlertEvaluationService ("app:alerts:evaluate").
 */
final class Version20260922211500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'C3.8 : table alert_rule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE alert_rule (id SERIAL NOT NULL, user_id INT NOT NULL, rule_type VARCHAR(30) NOT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_alert_rule_user ON alert_rule (user_id)');
        $this->addSql('ALTER TABLE alert_rule ADD CONSTRAINT FK_alert_rule_user FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert_rule DROP CONSTRAINT FK_alert_rule_user');
        $this->addSql('DROP TABLE alert_rule');
    }
}
