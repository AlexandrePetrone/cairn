<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Membership;
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
 * referent of the first. The project replaces its workspace, and the
 * workspace answers again when the mark of the project no longer holds.
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
        return array_filter(array_map(static fn (RoleReferent $referent): ?User => $referent->marked(), $this->resolve($project)));
    }

    /**
     * @return array<string, User> role id => its referent on the project
     */
    public function all(Project $project): array
    {
        return array_filter(array_map(static fn (RoleReferent $referent): ?User => $referent->user(), $this->resolve($project)));
    }

    /**
     * The roles someone is marked for on the project or on its workspace,
     * each with both marks and which one answers: what the Access page of
     * a project shows.
     *
     * @return array<string, RoleReferent> by role id
     */
    public function resolve(Project $project): array
    {
        $marks = [];
        foreach ($this->memberships->findReferentsApplyingTo($project) as $membership) {
            $marks[$membership->getRole()][null !== $membership->getProject() ? 'own' : 'inherited'] ??= $membership;
        }

        $holds = fn (?Membership $membership): bool => null !== $membership
            && $membership->getUser()->isActive()
            && $this->access->roleOn($membership->getUser(), $project) === $membership->getRole();

        $referents = [];
        foreach ($marks as $role => $mark) {
            $own = $mark['own'] ?? null;
            $inherited = $mark['inherited'] ?? null;
            $referents[$role] = new RoleReferent($own, $holds($own), $inherited, $holds($inherited));
        }

        return $referents;
    }
}
