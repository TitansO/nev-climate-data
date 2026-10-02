<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit post-C3 2026-10-02 (P-09): partial unique index on alert_rule(user_id, rule_type), scoped to active rows only (active = true) - prevents duplicate active rules under concurrent POST /api/alert-rules requests, see App\Entity\AlertRule\'s own UniqueConstraint comment for why it is partial rather than flat.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_alert_rule_user_type_active ON alert_rule (user_id, rule_type) WHERE active = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_alert_rule_user_type_active');
    }
}
