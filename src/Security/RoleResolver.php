<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Definition\InstanceDefinition;
use App\Config\Snapshot\ConfigRegistry;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\MembershipRepository;

/**
 * The effective role of a user on a project or a workspace: the broadest of
 * the roles granted directly, inherited from the workspace, and, for
 * internal users, the global roles of the configuration. A grant may only
 * widen what is inherited, never narrow it.
 */
final class RoleResolver
{
    public function __construct(
        private readonly MembershipRepository $repository,
        private readonly ConfigRegistry $config,
    ) {
    }

    public function roleOn(User $user, Project|Workspace $scope): ?string
    {
        $definition = $this->config->definition();
        $candidates = [];

        foreach ($this->repository->findByUser($user) as $membership) {
            $applies = $scope instanceof Project ? $membership->appliesTo($scope) : $membership->isOn($scope);
            if ($applies && $definition->hasRole($membership->getRole())) {
                $candidates[] = $membership->getRole();
            }
        }

        if ($user->isInternal()) {
            foreach ($definition->roles as $role) {
                if ($role->global) {
                    $candidates[] = $role->id;
                }
            }
        }

        return self::broadest($definition, $candidates);
    }

    /**
     * @param list<string> $roleIds
     */
    private static function broadest(InstanceDefinition $definition, array $roleIds): ?string
    {
        $best = null;
        foreach ($roleIds as $roleId) {
            if (null === $best || $definition->roleRank($roleId) < $definition->roleRank($best)) {
                $best = $roleId;
            }
        }

        return $best;
    }
}
