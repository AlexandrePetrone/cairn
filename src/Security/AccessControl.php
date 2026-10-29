<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\ConfigRegistry;
use App\Config\Definition\TransitionDefinition;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\MembershipRepository;

/**
 * The one place that decides what a user may do. Takes the acting user
 * explicitly so that handlers, console commands and webhooks can ask the
 * same question as controllers; the voters are thin adapters over it.
 *
 * Administrators may do everything. Internal users may view every project.
 * Everything else comes from the effective role and the configuration.
 * An archived project stays readable but grants no permission at all, to
 * anyone: when a permission to manage projects exists, it will be the one
 * exception, so that a project can be unarchived.
 */
final class AccessControl
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly ConfigRegistry $config,
    ) {
    }

    /**
     * The effective role of a user on a project or a workspace: the broadest
     * of the roles granted directly and inherited from the workspace. A grant
     * may only widen what is inherited, never narrow it. A membership whose
     * role no longer exists in the configuration grants nothing. Being
     * internal grants no role: it only opens every project to reading.
     */
    public function roleOn(User $user, Project|Workspace $scope): ?string
    {
        $definition = $this->config->definition();
        $best = null;

        foreach ($this->memberships->findByUser($user) as $membership) {
            $applies = $scope instanceof Project ? $membership->appliesTo($scope) : $membership->isOn($scope);
            if ($applies && $definition->hasRole($membership->getRole())) {
                $best = self::broadest($definition->roleRank(...), $best, $membership->getRole());
            }
        }

        return $best;
    }

    public function canView(User $user, Project|Workspace $scope): bool
    {
        return $user->isInternal() || null !== $this->roleOn($user, $scope);
    }

    public function isGranted(User $user, Permission $permission, Project|Workspace $scope): bool
    {
        if ($scope instanceof Project && $scope->isArchived()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $role = $this->roleOn($user, $scope);

        return null !== $role && \in_array($role, $this->config->definition()->rolesGranted($permission), true);
    }

    /**
     * An external user sees an issue only if the project is open to them
     * and neither the issue nor its tracker is private. SearchIssuesHandler
     * applies the same rule in SQL; keep them in step.
     */
    public function canSee(User $user, Issue $issue): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return !$issue->isPrivate() && $this->canView($user, $issue->getProject());
    }

    /**
     * Private comments are for internal users only. ShowIssueHandler
     * applies the same rule in SQL; keep them in step.
     */
    public function canSeeComment(User $user, Comment $comment): bool
    {
        return $this->canSee($user, $comment->getIssue()) && ($user->isInternal() || !$comment->isPrivate());
    }

    public function canTransition(User $user, TransitionDefinition $transition, Project $project): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $role = $this->roleOn($user, $project);

        return null !== $role && \in_array($role, $transition->roles, true);
    }

    /**
     * @param callable(string): int $rank 0 is the broadest role
     */
    private static function broadest(callable $rank, ?string $current, string $candidate): string
    {
        return null === $current || $rank($candidate) < $rank($current) ? $candidate : $current;
    }
}
