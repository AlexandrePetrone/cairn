<?php

declare(strict_types=1);

namespace App\Config\Definition;

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

    /** @var array<string, WorkflowDefinition> */
    public array $workflows;

    /**
     * @param MigrationPolicy             $migrationPolicy what the sync does with issues on a removed place
     * @param list<RoleDefinition>        $roles           declared from the broadest to the narrowest
     * @param array<string, list<string>> $permissions     Permission value => role ids
     * @param list<FieldDefinition>       $fields
     * @param list<LinkTypeDefinition>    $links
     * @param list<WorkflowDefinition>    $workflows
     */
    public function __construct(
        public MigrationPolicy $migrationPolicy,
        array $roles,
        public array $permissions,
        array $fields,
        array $links,
        array $workflows,
    ) {
        $this->roles = Definitions::indexById($roles, RoleDefinition::class, 'roles');
        $this->fields = Definitions::indexById($fields, FieldDefinition::class, 'fields');
        $this->links = Definitions::indexById($links, LinkTypeDefinition::class, 'links');
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

    public function hasWorkflow(string $id): bool
    {
        return isset($this->workflows[$id]);
    }

    public function workflow(string $id): WorkflowDefinition
    {
        return $this->workflows[$id] ?? throw new InvalidDefinitionException(\sprintf('Unknown workflow "%s".', $id));
    }
}
