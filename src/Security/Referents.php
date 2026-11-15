<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\MembershipRepository;

/**
 * Who a role answers through on a project: the member someone marked as
 * its referent, on the project or, failing that, on its workspace. Nobody
 * is a referent by default, so a role may have none; what names one (the
 * automation rules) then does nothing. A mark only holds while the
 * account is active and the role is still the one the person has on the
 * project: a referent given a broader role there is no longer the
 * referent of the first.
 */
final class Referents
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly AccessControl $access,
    ) {
    }

    public function of(Project $project, string $role): ?User
    {
        return $this->all($project)[$role] ?? null;
    }

    /**
     * Who is marked as the referent of each role, whether the mark still
     * holds or not: what explains a role without a referent when someone
     * was designated (a deactivated account, another role since).
     *
     * @return array<string, User> role id => the person marked, on the project first
     */
    public function marked(Project $project): array
    {
        $marked = [];
        foreach ($this->memberships->findReferentsApplyingTo($project) as $membership) {
            $marked[$membership->getRole()] ??= $membership->getUser();
        }

        return $marked;
    }

    /**
     * @return array<string, User> role id => its referent on the project
     */
    public function all(Project $project): array
    {
        $referents = [];
        foreach ($this->memberships->findReferentsApplyingTo($project) as $membership) {
            $user = $membership->getUser();
            $role = $membership->getRole();
            if (isset($referents[$role]) || !$user->isActive() || $this->access->roleOn($user, $project) !== $role) {
                continue;
            }
            $referents[$role] = $user;
        }

        return $referents;
    }
}
