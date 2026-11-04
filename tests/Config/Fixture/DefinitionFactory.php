<?php

declare(strict_types=1);

namespace App\Tests\Config\Fixture;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\FieldType;
use App\Config\MigrationPolicy;
use App\Config\TransitionStyle;
use App\Entity\Enum\PlaceStatus;
use App\Security\Permission;

/**
 * Small, valid definitions for tests. Every piece can be replaced.
 */
final class DefinitionFactory
{
    /**
     * @return list<RoleDefinition>
     */
    public static function roles(): array
    {
        return [
            new RoleDefinition(id: 'project_manager', label: 'Chef de projet', internalOnly: true),
            new RoleDefinition(id: 'developer', label: 'Développeur', internalOnly: true),
            new RoleDefinition(id: 'client', label: 'Client'),
        ];
    }

    /**
     * @return list<FieldDefinition>
     */
    public static function fields(): array
    {
        return [
            new FieldDefinition(id: 'url', label: 'URL', type: FieldType::Url),
            new FieldDefinition(id: 'environment', label: 'Environnement', type: FieldType::Select, options: ['prod' => 'Production', 'preprod' => 'Préproduction']),
        ];
    }

    /**
     * A bug workflow: new → in_progress → to_test → done, with to_test optional.
     *
     * @param list<string> $migrateInProgressFrom
     */
    public static function bugWorkflow(bool $withToTest = true, array $migrateInProgressFrom = [], string $label = 'Anomalie'): WorkflowDefinition
    {
        $places = [
            new PlaceDefinition(id: 'new', label: 'Nouveau', status: PlaceStatus::Open),
            new PlaceDefinition(id: 'in_progress', label: 'En cours', status: PlaceStatus::InProgress, migrateFrom: $migrateInProgressFrom),
        ];
        $transitions = [
            new TransitionDefinition(id: 'start', label: 'Démarrer', from: ['new'], to: 'in_progress', roles: ['developer']),
        ];

        if ($withToTest) {
            $places[] = new PlaceDefinition(id: 'to_test', label: 'À recetter', status: PlaceStatus::Waiting);
            $transitions[] = new TransitionDefinition(id: 'deliver', label: 'Livrer', from: ['in_progress'], to: 'to_test', roles: ['developer']);
            $transitions[] = new TransitionDefinition(id: 'accept', label: 'Valider', from: ['to_test'], to: 'done', roles: ['client', 'project_manager'], style: TransitionStyle::Primary);
            $transitions[] = new TransitionDefinition(id: 'refuse', label: 'Refuser', from: ['to_test'], to: 'in_progress', roles: ['client'], style: TransitionStyle::Danger, requiresComment: true, commentPrompt: 'Pourquoi ?');
        } else {
            $transitions[] = new TransitionDefinition(id: 'finish', label: 'Terminer', from: ['in_progress'], to: 'done', roles: ['developer']);
        }

        $places[] = new PlaceDefinition(id: 'done', label: 'Fermée', status: PlaceStatus::Closed);

        return new WorkflowDefinition(
            id: 'bug',
            label: $label,
            initial: 'new',
            places: $places,
            transitions: $transitions,
            fields: [new FieldUsage('environment', required: true), new FieldUsage('url')],
            fallbackPlace: 'new',
        );
    }

    /**
     * @param list<WorkflowDefinition>|null    $workflows
     * @param list<RoleDefinition>|null        $roles
     * @param list<FieldDefinition>|null       $fields
     * @param array<string, list<string>>|null $permissions
     */
    public static function definition(
        ?array $workflows = null,
        ?array $roles = null,
        ?array $fields = null,
        ?array $permissions = null,
        MigrationPolicy $migrationPolicy = MigrationPolicy::Strict,
    ): InstanceDefinition {
        return new InstanceDefinition(
            migrationPolicy: $migrationPolicy,
            roles: $roles ?? self::roles(),
            permissions: $permissions ?? [
                Permission::IssueCreate->value => ['project_manager', 'developer', 'client'],
                Permission::IssueEdit->value => ['project_manager'],
                Permission::IssueAssign->value => ['project_manager', 'developer'],
                Permission::IssueSetCriticality->value => ['project_manager', 'developer', 'client'],
                Permission::IssueSetPrivate->value => ['project_manager'],
                Permission::IssueSetRelease->value => ['project_manager', 'developer'],
                Permission::IssueChangeTracker->value => ['project_manager', 'developer'],
                Permission::CommentCreate->value => ['project_manager', 'developer', 'client'],
                Permission::CommentSetPrivate->value => ['project_manager', 'developer'],
                Permission::CommentEdit->value => ['project_manager'],
                Permission::ProjectConfigure->value => ['project_manager'],
                Permission::ProjectManageMembers->value => ['project_manager'],
                Permission::ReleaseManage->value => ['project_manager'],
            ],
            fields: $fields ?? self::fields(),
            links: [
                new LinkTypeDefinition(id: 'related', label: 'Lié à'),
                new LinkTypeDefinition(id: 'blocks', label: 'Bloque', inverseLabel: 'Bloqué par'),
            ],
            workflows: $workflows ?? [self::bugWorkflow()],
        );
    }
}
