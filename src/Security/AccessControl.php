<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Snapshot\ConfigRegistry;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;

/**
 * The one place that decides what a user may do. Takes the acting user
 * explicitly so that handlers, console commands and webhooks can ask the
 * same question as controllers; the voters are thin adapters over it.
 *
 * Administrators may do everything. Internal users may view every project.
 * Everything else comes from the effective role and the configuration.
 */
final class AccessControl
{
    public function __construct(
        private readonly RoleResolver $roles,
        private readonly ConfigRegistry $config,
    ) {
    }

    public function roleOn(User $user, Project|Workspace $scope): ?string
    {
        return $this->roles->roleOn($user, $scope);
    }

    public function canView(User $user, Project|Workspace $scope): bool
    {
        return $user->isInternal() || null !== $this->roles->roleOn($user, $scope);
    }

    public function isGranted(User $user, Permission $permission, Project|Workspace $scope): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $role = $this->roles->roleOn($user, $scope);

        return null !== $role && \in_array($role, $this->config->definition()->rolesGranted($permission), true);
    }
}
