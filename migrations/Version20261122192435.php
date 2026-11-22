<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How a role moves issues is its own: freely, or by the actions of the
 * workflows. The roles that held `issue.bypass_workflow` move freely: the
 * permission is gone, and so are they from the actions, which are for the
 * roles held to the workflow. A place of a workflow may stand beside a
 * place of the path its issues follow, for its diagram.
 */
final class Version20261122192435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A role moves issues freely or by the actions of the workflows, in place of the issue.bypass_workflow permission; a place of a workflow may stand beside its path.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE config_role ADD free_workflow BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE config_role SET free_workflow = true WHERE id IN (SELECT role_id FROM config_role_permission WHERE permission = 'issue.bypass_workflow')");
        $this->addSql("DELETE FROM config_role_permission WHERE permission = 'issue.bypass_workflow'");
        $this->addSql('DELETE FROM config_transition_role WHERE role_id IN (SELECT id FROM config_role WHERE free_workflow)');

        $this->addSql('ALTER TABLE config_workflow_place ADD beside_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              config_workflow_place
            ADD
              CONSTRAINT FK_4FC8AB27A64DE9 FOREIGN KEY (beside_id) REFERENCES config_workflow_place (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);
        $this->addSql('CREATE INDEX IDX_4FC8AB27A64DE9 ON config_workflow_place (beside_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE config_workflow_place DROP CONSTRAINT FK_4FC8AB27A64DE9');
        $this->addSql('DROP INDEX IDX_4FC8AB27A64DE9');
        $this->addSql('ALTER TABLE config_workflow_place DROP beside_id');

        // The actions the freed roles left are not known any more: they get the permission back, nothing else.
        $this->addSql("INSERT INTO config_role_permission (role_id, permission) SELECT id, 'issue.bypass_workflow' FROM config_role WHERE free_workflow");
        $this->addSql('ALTER TABLE config_role DROP free_workflow');
    }
}
