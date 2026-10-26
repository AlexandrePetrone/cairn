<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * A role that can be granted on a workspace or a project. Roles are declared
 * from the broadest to the narrowest: a direct grant may only widen a role
 * inherited from the workspace.
 */
final readonly class RoleDefinition
{
    /**
     * @param bool $internalOnly the role can only be granted to internal users
     * @param bool $global       the role applies to every project without an explicit grant
     */
    public function __construct(
        public string $id,
        public string $label,
        public bool $internalOnly = false,
        public bool $global = false,
    ) {
        Definitions::assertId($id, 'roles');
        Definitions::assertLabel($label, "roles.$id.label");

        if ($global && !$internalOnly) {
            throw new InvalidDefinitionException(\sprintf('roles.%s: a global role must be internal only.', $id));
        }
    }
}
