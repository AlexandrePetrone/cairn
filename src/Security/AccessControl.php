<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\ConfigRegistry;
use App\Config\Definition\TransitionDefinition;
use App\Entity\Attachment;
use App\Entity\BudgetLine;
use App\Entity\Comment;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Issue;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\MembershipRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The one place that decides what a user may do. Takes the acting user
 * explicitly so that handlers, console commands and webhooks can ask the
 * same question as controllers; the voters are thin adapters over it.
 *
 * Administrators may do everything. Internal users may view every project.
 * Everything else comes from the effective role and the configuration.
 * An archived project, or a project of an archived workspace, stays
 * readable but grants no permission and no transition at all, to anyone:
 * administrators unarchive them from the administration, which
 * permissions do not govern.
 *
 * A page asks dozens of decisions about the same user: their memberships
 * are loaded once per request. reset() forgets them: the kernel calls it
 * between requests, EventListener/AccessControlResetter when a membership
 * is written.
 */
final class AccessControl implements ResetInterface
{
    /** @var array<int, list<Membership>> user id => memberships */
    private array $memberships = [];

    public function __construct(
        private readonly MembershipRepository $membershipRepository,
        private readonly ConfigRegistry $config,
    ) {
    }

    /**
     * The effective role of a user on a project or a workspace: the broadest
     * of the roles granted directly and inherited from the workspace. A grant
     * may only widen what is inherited, never narrow it. Being
     * internal grants no role: it only opens every project to reading.
     */
    public function roleOn(User $user, Project|Workspace $scope): ?string
    {
        $definition = $this->config->definition();
        $best = null;

        foreach ($this->membershipsOf($user) as $membership) {
            $applies = $scope instanceof Project ? $membership->appliesTo($scope) : $membership->isOn($scope);
            if ($applies) {
                $best = self::broadest($definition->roleRank(...), $best, $membership->getRole());
            }
        }

        return $best;
    }

    /**
     * The API is opened by the account, not by a role on a project: it is
     * for internal users. What a token then reads and does is
     * decided by the same rules as in the pages.
     */
    public function canUseApi(User $user): bool
    {
        return $user->isActive() && $user->isInternal();
    }

    public function canView(User $user, Project|Workspace $scope): bool
    {
        return $user->isInternal() || null !== $this->roleOn($user, $scope);
    }

    /**
     * The budgets of a project that has some are read by whoever holds
     * `budget.view` or `budget.manage` there: the one place that says so,
     * for the budgets pages, their entry in the navigation and the card of
     * an issue. Reading outlives the archiving of the project, which
     * refuses every permission: what a client consumed stays consultable,
     * as the issues do. Writing does not: `budget.manage` is asked as any
     * permission.
     */
    public function canReadBudgets(User $user, Project $project): bool
    {
        if (!$project->hasBudgets()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $role = $this->roleOn($user, $project);
        $definition = $this->config->definition();

        return null !== $role
            && (\in_array($role, $definition->rolesGranted(Permission::BudgetView), true) || \in_array($role, $definition->rolesGranted(Permission::BudgetManage), true));
    }

    /**
     * The quantity of a line to estimate is a draft, or an estimate to do
     * again: whoever manages the budgets of the project reads it, since
     * they write it. Everyone else reads it once the line waits for an
     * approval. The one place that says so, for the budgets
     * pages and the card of an issue; like reading the budgets, it
     * outlives the archiving of the project.
     */
    public function canSeeQuantity(User $user, BudgetLine $line): bool
    {
        if (BudgetLineStatus::ToEstimate !== $line->getStatus() || $user->isAdmin()) {
            return true;
        }
        $role = $this->roleOn($user, $line->getBudget()->getProject());

        return null !== $role && \in_array($role, $this->config->definition()->rolesGranted(Permission::BudgetManage), true);
    }

    public function isGranted(User $user, Permission $permission, Project|Workspace $scope): bool
    {
        if ($scope instanceof Project ? $scope->isReadOnly() : $scope->isArchived()) {
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

    /**
     * The content of an issue (title, description, fields, files on the
     * issue) is its author's, as long as their role still lets them write
     * in the project; `issue.edit` grants it on the issues of others.
     */
    public function canEditIssue(User $user, Issue $issue): bool
    {
        $project = $issue->getProject();
        $own = $issue->getAuthor()->getId() === $user->getId() && $this->isGranted($user, Permission::IssueCreate, $project);

        return $own || $this->isGranted($user, Permission::IssueEdit, $project);
    }

    /**
     * The body and files of a comment are its author's, as long as their
     * role still lets them comment; `comment.edit` grants it on the
     * comments of others. Editing and deleting are the same right.
     */
    public function canEditComment(User $user, Comment $comment): bool
    {
        if (!$this->canSeeComment($user, $comment)) {
            return false;
        }
        $project = $comment->getIssue()->getProject();
        $own = $comment->getAuthor()?->getId() === $user->getId() && $this->isGranted($user, Permission::CommentCreate, $project);

        return $own || $this->isGranted($user, Permission::CommentEdit, $project);
    }

    /**
     * A pending file is seen by its uploader only; then it follows its
     * comment or its issue. ShowIssueHandler applies the same rule in SQL;
     * keep them in step.
     */
    public function canSeeAttachment(User $user, Attachment $attachment): bool
    {
        $issue = $attachment->getIssue();
        $comment = $attachment->getComment();

        return match (true) {
            null === $issue => $attachment->getUploadedBy()->getId() === $user->getId(),
            null !== $comment => $this->canSeeComment($user, $comment),
            default => $this->canSee($user, $issue),
        };
    }

    public function canTransition(User $user, TransitionDefinition $transition, Project $project): bool
    {
        if ($project->isReadOnly()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $role = $this->roleOn($user, $project);

        return null !== $role && \in_array($role, $transition->roles, true);
    }

    /**
     * Whether the comment a transition asks for is required of this user
     * on this project: of everyone but those whose role holds
     * `issue.bypass_workflow`.
     */
    public function commentRequired(User $user, TransitionDefinition $transition, Project $project): bool
    {
        return $transition->requiresComment && !$this->isGranted($user, Permission::IssueBypassWorkflow, $project);
    }

    /**
     * The actions of its workflow the user may take on the issue from where
     * it stands: those leaving its place that their role on its project
     * may trigger.
     *
     * @return list<TransitionDefinition>
     */
    public function transitionsFor(User $user, Issue $issue): array
    {
        $workflow = $this->config->definition()->workflow($issue->getTracker()->getWorkflowId());

        return array_values(array_filter(
            $workflow->transitionsFrom($issue->getPlace()),
            fn (TransitionDefinition $transition): bool => $this->canTransition($user, $transition, $issue->getProject()),
        ));
    }

    /**
     * Where the user may take the issue from where it stands, in the order
     * of its workflow: the workflow first, one move per action that leads
     * to a place; a place no action of theirs leads to is offered outside
     * the workflow to whoever holds `issue.bypass_workflow`. The step menu of
     * the issue page and the kanban both read this, so that an action is
     * applied whenever there is one.
     *
     * @return list<PlaceMove>
     */
    public function movesFor(User $user, Issue $issue): array
    {
        $workflow = $this->config->definition()->workflow($issue->getTracker()->getWorkflowId());
        $transitions = $this->transitionsFor($user, $issue);
        $force = $this->isGranted($user, Permission::IssueBypassWorkflow, $issue->getProject());

        $moves = [];
        foreach ($this->config->definition()->placesOf($workflow) as $place) {
            if ($place->id === $issue->getPlace()) {
                continue;
            }
            $leading = array_filter($transitions, static fn (TransitionDefinition $transition): bool => $transition->to === $place->id);
            foreach ($leading as $transition) {
                $moves[] = new PlaceMove($place, $transition);
            }
            if ([] === $leading && $force) {
                $moves[] = new PlaceMove($place);
            }
        }

        return $moves;
    }

    public function reset(): void
    {
        $this->memberships = [];
    }

    /**
     * @return list<Membership>
     */
    private function membershipsOf(User $user): array
    {
        return $this->memberships[(int) $user->getId()] ??= $this->membershipRepository->findByUser($user);
    }

    /**
     * @param callable(string): int $rank 0 is the broadest role
     */
    private static function broadest(callable $rank, ?string $current, string $candidate): string
    {
        return null === $current || $rank($candidate) < $rank($current) ? $candidate : $current;
    }
}
