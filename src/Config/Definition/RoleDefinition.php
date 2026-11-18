<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * A role that can be granted on a workspace or a project. Roles are ordered
 * from the broadest to the narrowest: a direct grant may only widen a role
 * inherited from the workspace.
 */
final readonly class RoleDefinition
{
    /**
     * @param bool $internalOnly     the role can only be granted to internal users
     * @param bool $followsNewIssues members holding this role on a project are
     *                               subscribed to every new issue of the project
     *                               (they can unsubscribe issue by issue)
     */
    public function __construct(
        public string $id,
        public string $label,
        public bool $internalOnly = false,
        public bool $followsNewIssues = false,
    ) {
    }
}
