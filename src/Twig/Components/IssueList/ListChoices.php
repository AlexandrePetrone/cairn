<?php

declare(strict_types=1);

namespace App\Twig\Components\IssueList;

use App\Entity\Release;
use App\Entity\Tracker;
use App\Entity\User;
use App\Form\Issue\IssueListFilters;
use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\IssueSort;
use App\Message\Query\Issue\SearchIssues;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListTrackers;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\Query\Project\ProjectSummary;
use App\Message\QueryBus;
use App\Repository\ReleaseRepository;
use App\Security\Viewer;
use App\Workflow\PlaceCatalog;
use App\Workflow\PlaceChoice;

/**
 * What an issue list offers its viewer to filter and group by, and the
 * search it runs: the one place that turns a scope and the filters of an
 * address into a SearchIssues, for the table and for the columns of the
 * kanban alike, keeping of the filters what the list offers. Users see
 * places, never statuses: a place is named with its workflow, so a list
 * mixing workflows keeps their places apart. Each choice is read once for
 * a scope, whichever component asks first.
 */
final class ListChoices
{
    /** @var \WeakMap<ListScope, list<Tracker>> */
    private \WeakMap $trackers;

    /** @var \WeakMap<ListScope, list<Release>> */
    private \WeakMap $releases;

    /** @var \WeakMap<ListScope, list<PlaceChoice>> */
    private \WeakMap $places;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly ReleaseRepository $releaseRepository,
        private readonly PlaceCatalog $placeCatalog,
    ) {
        $this->trackers = new \WeakMap();
        $this->releases = new \WeakMap();
        $this->places = new \WeakMap();
    }

    /**
     * The search of the list as it is arranged.
     */
    public function search(ListScope $scope, IssueListFilters $filters): SearchIssues
    {
        return new SearchIssues(
            viewer: $this->viewer->get(),
            projectId: $scope->project?->getId(),
            assigneeId: $scope->assigneeId,
            authorId: $scope->authorId,
            place: $scope->offers('place') ? $this->knownPlaces($scope, $filters) : [],
            project: $scope->offers('project') ? $filters->projects : [],
            tracker: null !== $scope->tracker ? [(int) $scope->tracker->getId()] : ($scope->offers('tracker') ? $filters->trackers : []),
            assignee: $scope->offers('assignee') ? $filters->assignees : [],
            criticality: $scope->offers('criticality') ? $filters->criticality : [],
            release: [] !== $filters->releases && $this->offersReleases($scope) ? $filters->releases : [],
            q: $filters->q,
            closed: $filters->closed,
            page: $filters->page,
            sort: $scope->sort($filters),
            order: $filters->order,
            group: $this->grouping($scope, $filters),
        );
    }

    /**
     * The search of one column of the kanban of a tracker: the issues at
     * that place, in the default order, in one page of `$perPage`. The
     * place filter, the sort and the grouping belong to the list.
     */
    public function searchColumn(ListScope $scope, IssueListFilters $filters, string $placeId, int $perPage): SearchIssues
    {
        $tracker = $scope->tracker ?? throw new \LogicException('Only the list of a tracker has a kanban.');

        return new SearchIssues(
            viewer: $this->viewer->get(),
            projectId: $tracker->getProject()->getId(),
            assigneeId: $scope->assigneeId,
            authorId: $scope->authorId,
            place: [PlaceCatalog::key($tracker->getWorkflowId(), $placeId)],
            tracker: [(int) $tracker->getId()],
            assignee: $scope->offers('assignee') ? $filters->assignees : [],
            criticality: $scope->offers('criticality') ? $filters->criticality : [],
            release: [] !== $filters->releases && $this->offersReleases($scope) ? $filters->releases : [],
            q: $filters->q,
            sort: IssueSort::Rank,
            order: \SortDirection::Descending,
            perPage: $perPage,
        );
    }

    /**
     * The places offered by the filter: those of the workflows of the list,
     * the tracker's, the project's trackers', every one across projects.
     *
     * @return list<PlaceChoice>
     */
    public function places(ListScope $scope): array
    {
        return $this->places[$scope] ??= match (true) {
            null !== $scope->tracker => $this->placeCatalog->choices([$scope->tracker->getWorkflowId()]),
            null !== $scope->project => $this->placeCatalog->choices(array_map(static fn (Tracker $tracker): string => $tracker->getWorkflowId(), $this->trackers($scope))),
            default => $this->placeCatalog->choices(),
        };
    }

    /**
     * Whether the list holds the issues of several workflows, whose places
     * the filter and the groups then name with their workflow.
     */
    public function mixesWorkflows(ListScope $scope): bool
    {
        return \count(array_unique(array_map(static fn (PlaceChoice $choice): string => $choice->workflowLabel, $this->places($scope)))) > 1;
    }

    /**
     * The trackers of the project the viewer sees, archived ones included.
     *
     * @return list<Tracker>
     */
    public function trackers(ListScope $scope): array
    {
        return $this->trackers[$scope] ??= null === $scope->project ? [] : $this->queries->ask(new ListTrackers($this->viewer->get(), $scope->project, archived: true));
    }

    /**
     * The releases of the project, open ones first.
     *
     * @return list<Release>
     */
    public function releases(ListScope $scope): array
    {
        return $this->releases[$scope] ??= null === $scope->project ? [] : $this->releaseRepository->findByProject($scope->project);
    }

    /**
     * The release filter is offered on the lists of a project that has
     * releases, when the page offers it.
     */
    public function offersReleases(ListScope $scope): bool
    {
        return $scope->offers('release') && [] !== $this->releases($scope);
    }

    /**
     * @return list<User>
     */
    public function members(ListScope $scope): array
    {
        return null === $scope->project ? [] : $this->queries->ask(new ListProjectMembers($scope->project));
    }

    /**
     * @return list<ProjectSummary>
     */
    public function projects(): array
    {
        return $this->queries->ask(new ListVisibleProjects($this->viewer->get()));
    }

    /**
     * What the list can group by: the project only across projects, the
     * assignee unless the list is the viewer's own, the release in a
     * project that has some.
     *
     * @return list<IssueGrouping>
     */
    public function groupings(ListScope $scope): array
    {
        return array_values(array_filter(IssueGrouping::cases(), fn (IssueGrouping $grouping): bool => match ($grouping) {
            IssueGrouping::Project => $scope->showProject,
            IssueGrouping::Assignee => null === $scope->assigneeId,
            IssueGrouping::Release => [] !== $this->releases($scope),
            default => true,
        }));
    }

    /**
     * The grouping of the list, when it offers the one asked for.
     */
    public function grouping(ListScope $scope, IssueListFilters $filters): ?IssueGrouping
    {
        return \in_array($filters->group, $this->groupings($scope), true) ? $filters->group : null;
    }

    /**
     * The chosen places that belong to the workflows of the list.
     *
     * @return list<string>
     */
    private function knownPlaces(ListScope $scope, IssueListFilters $filters): array
    {
        return array_values(array_intersect($filters->places, array_map(static fn (PlaceChoice $choice): string => $choice->key, $this->places($scope))));
    }
}
