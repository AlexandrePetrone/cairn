<?php

declare(strict_types=1);

namespace App\Config\Definition;

use App\Config\FieldValue;
use App\Config\MigrationPolicy;
use App\Security\Permission;

/**
 * The whole instance configuration, as returned by the configuration file
 * and as rebuilt from a snapshot. Immutable; cross-references are checked by
 * the ConfigValidator, not here.
 */
final readonly class InstanceDefinition
{
    /** @var array<string, RoleDefinition> id => role, broadest first */
    public array $roles;

    /** @var array<string, FieldDefinition> */
    public array $fields;

    /** @var array<string, LinkTypeDefinition> */
    public array $links;

    /** @var array<string, PlaceDefinition> id => place, in the order lists show them */
    public array $places;

    /** @var array<string, WorkflowDefinition> */
    public array $workflows;

    /**
     * @param MigrationPolicy             $migrationPolicy what the sync does with issues on a removed place
     * @param list<RoleDefinition>        $roles           declared from the broadest to the narrowest
     * @param array<string, list<string>> $permissions     Permission value => role ids
     * @param list<FieldDefinition>       $fields
     * @param list<LinkTypeDefinition>    $links
     * @param list<PlaceDefinition>       $places          every step of the instance; each workflow names those it uses
     * @param list<WorkflowDefinition>    $workflows
     */
    public function __construct(
        public MigrationPolicy $migrationPolicy,
        array $roles,
        public array $permissions,
        array $fields,
        array $links,
        array $places,
        array $workflows,
    ) {
        $this->roles = Definitions::indexById($roles, RoleDefinition::class, 'roles');
        $this->fields = Definitions::indexById($fields, FieldDefinition::class, 'fields');
        $this->links = Definitions::indexById($links, LinkTypeDefinition::class, 'links');
        $this->places = Definitions::indexById($places, PlaceDefinition::class, 'places');
        $this->workflows = Definitions::indexById($workflows, WorkflowDefinition::class, 'workflows');

        foreach ($permissions as $permission => $roleIds) {
            if (!\is_string($permission) || !\is_array($roleIds)) {
                throw new InvalidDefinitionException('permissions: expected a map of permission => list of role ids.');
            }
            Definitions::assertIdList($roleIds, "permissions.$permission");
        }
    }

    public function hasRole(string $id): bool
    {
        return isset($this->roles[$id]);
    }

    public function role(string $id): RoleDefinition
    {
        return $this->roles[$id] ?? throw new InvalidDefinitionException(\sprintf('Unknown role "%s".', $id));
    }

    /** @return list<string> */
    public function roleIds(): array
    {
        return array_keys($this->roles);
    }

    /**
     * Position of the role in the declaration order: 0 is the broadest.
     */
    public function roleRank(string $id): int
    {
        $rank = array_search($id, $this->roleIds(), true);
        if (false === $rank) {
            throw new InvalidDefinitionException(\sprintf('Unknown role "%s".', $id));
        }

        return $rank;
    }

    /** @return list<string> role ids holding the permission */
    public function rolesGranted(Permission $permission): array
    {
        return $this->permissions[$permission->value] ?? [];
    }

    public function hasField(string $id): bool
    {
        return isset($this->fields[$id]);
    }

    public function field(string $id): FieldDefinition
    {
        return $this->fields[$id] ?? throw new InvalidDefinitionException(\sprintf('Unknown field "%s".', $id));
    }

    public function hasLink(string $id): bool
    {
        return isset($this->links[$id]);
    }

    public function link(string $id): LinkTypeDefinition
    {
        return $this->links[$id] ?? throw new InvalidDefinitionException(\sprintf('Unknown link type "%s".', $id));
    }

    /**
     * The filled-in fields of a workflow, in its order, as a reader sees
     * them: empty ones left out, internal-only ones kept for internal
     * readers, option values replaced by their labels.
     *
     * @param array<string, mixed> $values field id => stored value
     *
     * @return list<FieldValue>
     */
    public function fieldValues(WorkflowDefinition $workflow, array $values, bool $internal): array
    {
        $fields = [];
        foreach ($workflow->fields as $usage) {
            $field = $this->field($usage->field);
            $value = $values[$field->id] ?? null;
            if (null === $value || '' === $value || ($field->internalOnly && !$internal)) {
                continue;
            }
            $fields[] = new FieldValue($field, $field->display($value));
        }

        return $fields;
    }

    public function hasPlace(string $id): bool
    {
        return isset($this->places[$id]);
    }

    /**
     * The place, or null when it left the configuration (an issue may
     * still sit on it until the sync migrates it).
     */
    public function findPlace(string $id): ?PlaceDefinition
    {
        return $this->places[$id] ?? null;
    }

    public function place(string $id): PlaceDefinition
    {
        return $this->places[$id] ?? throw new InvalidDefinitionException(\sprintf('Unknown place "%s".', $id));
    }

    /**
     * The places of a workflow, in its order: the columns of its kanban.
     *
     * @return list<PlaceDefinition>
     */
    public function placesOf(WorkflowDefinition $workflow): array
    {
        return array_map($this->place(...), $workflow->places);
    }

    /**
     * The places the given workflows use, in the order of the instance:
     * what a list of their issues offers to filter and group by.
     *
     * @param list<string> $workflowIds unknown ids are skipped
     *
     * @return list<PlaceDefinition>
     */
    public function placesIn(array $workflowIds): array
    {
        $used = [];
        foreach ($workflowIds as $workflowId) {
            $used += array_flip($this->workflows[$workflowId]->places ?? []);
        }

        return array_values(array_intersect_key($this->places, $used));
    }

    public function hasWorkflow(string $id): bool
    {
        return isset($this->workflows[$id]);
    }

    /**
     * @return array<string, string> workflow id => label, in declaration order
     */
    public function workflowLabels(): array
    {
        return array_map(static fn (WorkflowDefinition $workflow): string => $workflow->label, $this->workflows);
    }

    public function workflow(string $id): WorkflowDefinition
    {
        return $this->workflows[$id] ?? throw new InvalidDefinitionException(\sprintf('Unknown workflow "%s".', $id));
    }
}
