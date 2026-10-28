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
     */
    public function __construct(
        public string $id,
        public string $label,
        public bool $internalOnly = false,
    ) {
        Definitions::assertId($id, 'roles');
        Definitions::assertLabel($label, "roles.$id.label");
    }
}
