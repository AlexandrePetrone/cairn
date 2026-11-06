<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Issue;

use App\Config\Definition\TransitionDefinition;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\Query\Issue\ListSelectionActions;
use App\Message\Query\Issue\SelectionActions;
use App\Message\Query\Issue\SelectionTransition;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\QueryBus;
use App\Repository\IssueOrder;
use App\Repository\ReleaseRepository;
use App\Repository\TrackerRepository;
use App\Repository\Visibility;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Loads the selected issues the viewer sees (Repository/Visibility, an id
 * they may not see is dropped), sorted as the lists sort them, then keeps
 * the actions valid for all of them: the transitions available from each
 * issue's place to the viewer's role on its project, matched by id; the
 * rights that hold on every project; the people who are members of every
 * project, for the assignment; the open releases and the trackers a move
 * may target, when the selection holds the issues of one project, since
 * releases and trackers belong to one.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListSelectionActionsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
        private readonly AccessControl $access,
        private readonly QueryBus $queries,
        private readonly ReleaseRepository $releases,
        private readonly TrackerRepository $trackers,
    ) {
    }

    public function __invoke(ListSelectionActions $query): SelectionActions
    {
        $viewer = $query->viewer;
        $issues = $this->issues($viewer, $query->issueIds);
        if ([] === $issues) {
            return new SelectionActions([]);
        }

        $projects = [];
        foreach ($issues as $issue) {
            $projects[(int) $issue->getProject()->getId()] = $issue->getProject();
        }
        $everywhere = fn (Permission $permission): bool => array_all($projects, fn (Project $project): bool => $this->access->isGranted($viewer, $permission, $project));
        $canAssign = $everywhere(Permission::IssueAssign);
        $canComment = $everywhere(Permission::CommentCreate);
        $project = 1 === \count($projects) ? reset($projects) : null;

        return new SelectionActions(
            issues: $issues,
            transitions: $this->commonTransitions($viewer, $issues),
            canAssign: $canAssign,
            assignees: $canAssign ? $this->commonMembers($projects) : [],
            canComment: $canComment,
            canCommentPrivately: $canComment && $everywhere(Permission::CommentSetPrivate),
            canSetCriticality: $everywhere(Permission::IssueSetCriticality),
            releases: null !== $project && $everywhere(Permission::IssueSetRelease) ? $this->releases->findByProject($project, openOnly: true) : [],
            moveTrackers: null === $project ? [] : $this->moveTrackers($viewer, $project, $issues),
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Issue>
     */
    private function issues(User $viewer, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('i', 't', 'p', 'w', 'a')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->join('i.project', 'p')
            ->join('p.workspace', 'w')
            ->leftJoin('i.assignee', 'a')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids);
        IssueOrder::apply($queryBuilder);
        $this->visibility->restrictIssues($queryBuilder, $viewer);

        $rows = $queryBuilder->getQuery()->getResult();
        if (!\is_array($rows)) {
            throw new \LogicException('An object hydration query returns a list.');
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof Issue));
    }

    /**
     * @param non-empty-list<Issue> $issues
     *
     * @return list<SelectionTransition>
     */
    private function commonTransitions(User $viewer, array $issues): array
    {
        /** @var array<string, list<TransitionDefinition>>|null $common transition id => its definition for each issue */
        $common = null;
        foreach ($issues as $issue) {
            $available = [];
            foreach ($this->access->transitionsFor($viewer, $issue) as $transition) {
                $available[$transition->id] = $transition;
            }

            if (null === $common) {
                $common = array_map(static fn (TransitionDefinition $transition): array => [$transition], $available);
                continue;
            }
            $common = array_intersect_key($common, $available);
            foreach ($common as $id => $definitions) {
                $common[$id][] = $available[$id];
            }
        }

        $transitions = [];
        foreach ($common as $id => $definitions) {
            $first = $definitions[0];
            $transitions[] = new SelectionTransition(
                id: (string) $id,
                label: implode(' / ', array_values(array_unique(array_map(static fn (TransitionDefinition $transition): string => $transition->label, $definitions)))),
                style: $first->style,
                requiresComment: array_any($definitions, fn (TransitionDefinition $transition): bool => $this->access->commentRequired($viewer, $transition)),
                commentPrompt: array_find($definitions, static fn (TransitionDefinition $transition): bool => null !== $transition->commentPrompt)?->commentPrompt,
            );
        }

        return $transitions;
    }

    /**
     * The trackers of the project every issue may be moved to: an issue
     * stays in its own tracker with `issue.force_place`, goes to another
     * with `issue.change_tracker`, so a tracker is offered only when the
     * viewer holds the right it takes for each issue.
     *
     * @param non-empty-list<Issue> $issues
     *
     * @return list<Tracker>
     */
    private function moveTrackers(User $viewer, Project $project, array $issues): array
    {
        $force = $this->access->isGranted($viewer, Permission::IssueForcePlace, $project);
        $change = $this->access->isGranted($viewer, Permission::IssueChangeTracker, $project);
        if (!$force && !$change) {
            return [];
        }

        $candidates = [];
        foreach ($this->trackers->findByProject($project, includePrivate: $viewer->isInternal()) as $tracker) {
            $candidates[(int) $tracker->getId()] = $tracker;
        }
        foreach ($issues as $issue) {
            $candidates[(int) $issue->getTracker()->getId()] ??= $issue->getTracker();
        }

        return array_values(array_filter($candidates, static function (Tracker $tracker) use ($issues, $force, $change): bool {
            foreach ($issues as $issue) {
                $own = $issue->getTracker()->getId() === $tracker->getId();
                if ($own ? !$force : (!$change || !$tracker->isActive())) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param array<int, Project> $projects
     *
     * @return list<User>
     */
    private function commonMembers(array $projects): array
    {
        $members = null;
        foreach ($projects as $project) {
            $ofProject = [];
            foreach ($this->queries->ask(new ListProjectMembers($project)) as $member) {
                $ofProject[(int) $member->getId()] = $member;
            }
            $members = null === $members ? $ofProject : array_intersect_key($members, $ofProject);
        }

        return array_values($members ?? []);
    }
}
