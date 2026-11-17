<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\IssueLink;
use App\Entity\LinkType;
use App\Entity\Membership;
use App\Entity\Role;
use App\Entity\TemplateTracker;
use App\Entity\Tracker;
use App\Entity\Workflow;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * The data names what the configuration holds by its key, as a plain
 * string: the role of an access, the workflow of a tracker, the type of a
 * link. These columns stay strings for the entities and the queries, and
 * get a foreign key in the schema here, so that the database itself
 * refuses a role, a workflow or a link type that does not exist, and the
 * deletion of one still in use.
 *
 * The place of an issue has none: a key cannot say "a place of the
 * workflow of its tracker", which the commands that take a place out of a
 * workflow guarantee.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class ConfigurationReferences
{
    /** entity, its property, the configuration entity it names */
    private const array REFERENCES = [
        [Membership::class, 'role', Role::class],
        [Tracker::class, 'workflowId', Workflow::class],
        [TemplateTracker::class, 'workflowId', Workflow::class],
        [IssueLink::class, 'typeId', LinkType::class],
    ];

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $entityManager = $args->getEntityManager();
        $schema = $args->getSchema();

        foreach (self::REFERENCES as [$class, $property, $target]) {
            $metadata = $entityManager->getClassMetadata($class);
            $table = $schema->getTable($metadata->getTableName());
            $column = $metadata->getColumnName($property);

            $table->addIndex([$column], \sprintf('idx_%s_%s', $metadata->getTableName(), $column));
            $table->addForeignKeyConstraint(
                $entityManager->getClassMetadata($target)->getTableName(),
                [$column],
                ['id'],
                [],
                \sprintf('fk_%s_%s', $metadata->getTableName(), $column),
            );
        }
    }
}
