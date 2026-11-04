<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\IssueSort;
use App\Message\Query\Issue\SearchIssues;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\Query\Project\ProjectSummary;
use App\Message\QueryBus;
use App\Repository\Pagination;
use App\Repository\TrackerRepository;
use App\Security\Viewer;
use App\Workflow\PlaceCatalog;
use App\Workflow\PlaceChoice;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Every issue list but the home page's: the page sets the scope (a
 * project, a tracker, the viewer as assignee or as author) and the
 * filters it offers (`dimensions`); the filter values, the sorted column
 * and the grouping are props bound to the query string, so a list as the
 * user arranged it is an address to share. A value the list does not
 * know is ignored. Users see places, never statuses: the place filter and
 * groups name a place with its workflow (PlaceCatalog), so a list mixing
 * workflows keeps their places apart.
 * Rows can be checked, page by page; the selection goes to the selection
 * page, which holds the bulk actions. `List` is a reserved word, hence the
 * class name.
 */
#[AsLiveComponent('Issue:List')]
final class IssueList
{
    use DefaultActionTrait;

    public const array DIMENSIONS = ['place', 'project', 'tracker', 'criticality', 'assignee'];

    #[LiveProp]
    public ?Project $project = null;

    /** The tracker the list is scoped to; its filter is then not offered. */
    #[LiveProp]
    public ?Tracker $tracker = null;

    #[LiveProp]
    public ?int $assigneeId = null;

    #[LiveProp]
    public ?int $authorId = null;

    /** @var list<string> the filters offered, among self::DIMENSIONS */
    #[LiveProp]
    public array $dimensions = [];

    /** Adds the project column, for the lists across projects. */
    #[LiveProp]
    public bool $showProject = false;

    /** The address of the page, without the filters; the selection page comes back to it. */
    #[LiveProp]
    public ?string $listPath = null;

    /** @var list<string> keys of the chosen places, `workflow:place` (PlaceChoice::$key) */
    #[LiveProp(writable: true, url: true, onUpdated: 'restart')]
    public array $places = [];

    /**
     * Ids come from the browser as text: typed `list<int>`, those read from
     * the query string would be refused and silently dropped. They are
     * normalised to integers once hydrated.
     *
     * @var list<int|string>
     */
    #[LiveProp(writable: true, url: true, onUpdated: 'restart')]
    public array $projects = [];

    /** @var list<int|string> */
    #[LiveProp(writable: true, url: true, onUpdated: 'restart')]
    public array $trackers = [];

    /** @var list<int|string> user ids, 0 for unassigned */
    #[LiveProp(writable: true, url: true, onUpdated: 'restart')]
    public array $assignees = [];

    /** @var list<string> Criticality values */
    #[LiveProp(writable: true, url: true, onUpdated: 'restart')]
    public array $criticality = [];

    #[LiveProp(writable: true, url: true, onUpdated: 'restart')]
    public string $q = '';

    /** List closed issues too. */
    #[LiveProp(writable: true, url: true, onUpdated: 'restart')]
    public bool $closed = false;

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    /** The column sorted by, an IssueSort value; empty for the default order. */
    #[LiveProp(writable: true, url: true, onUpdated: 'restart')]
    public string $sort = '';

    /** `asc` or `desc`. */
    #[LiveProp(writable: true, url: true, onUpdated: 'restart')]
    public string $order = 'asc';

    /** What the issues are grouped by, an IssueGrouping value; empty for none. */
    #[LiveProp(writable: true, url: true, onUpdated: 'regroup')]
    public string $group = '';

    /** @var list<string> keys of the folded groups, kept while the grouping stays */
    #[LiveProp(writable: true)]
    public array $collapsed = [];

    /** @var list<int|string> ids of the checked rows of the page */
    #[LiveProp(writable: true)]
    public array $selected = [];

    /** The props bound to the query string, which the address of the list carries. */
    private const array URL_PROPS = ['places', 'projects', 'trackers', 'assignees', 'criticality', 'q', 'closed', 'page', 'sort', 'order', 'group'];

    /** @var Pagination<Issue>|null read once per render */
    private ?Pagination $issues = null;

    /** @var list<Tracker>|null read once per render */
    private ?array $projectTrackers = null;

    /** @var list<PlaceChoice>|null read once per render */
    private ?array $placeChoices = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly TrackerRepository $trackerRepository,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requests,
        private readonly PlaceCatalog $placeCatalog,
    ) {
    }

    #[PostMount]
    public function postMount(): void
    {
        $this->dimensions = array_values(array_intersect(self::DIMENSIONS, $this->dimensions));
        $this->listPath ??= $this->pagePath();
        $this->normalize();
    }

    /**
     * Keeps what the list understands of the values the browser sent: place
     * ids (those of no workflow of the list are ignored by the search),
     * known criticalities, ids, a page from 1, a column it shows, a grouping
     * it offers.
     */
    #[PostHydrate]
    public function normalize(): void
    {
        $this->places = array_values(array_unique(array_filter($this->places, static fn (mixed $place): bool => \is_string($place) && 1 === preg_match('/^[\w.-]+:[\w.-]+$/', $place))));
        $this->criticality = array_values(array_unique(array_map(static fn (Criticality $criticality): string => $criticality->value, self::criticalities($this->criticality))));
        $this->projects = self::ids($this->projects);
        $this->trackers = self::ids($this->trackers);
        $this->assignees = self::ids($this->assignees, withZero: true);
        $this->selected = self::ids($this->selected);
        $this->page = max(1, $this->page);
        $this->sort = $this->sortColumn()->value ?? '';
        $this->order = 'desc' === $this->order ? 'desc' : 'asc';
        $this->group = $this->grouping()->value ?? '';
        $this->collapsed = array_values(array_filter($this->collapsed, \is_string(...)));
    }

    /**
     * A filter changed: back to the first page, nothing checked.
     */
    public function restart(): void
    {
        $this->page = 1;
        $this->selected = [];
    }

    /**
     * Another grouping: back to the first page, every group open.
     */
    public function regroup(): void
    {
        $this->restart();
        $this->collapsed = [];
    }

    public function offers(string $dimension): bool
    {
        return \in_array($dimension, $this->dimensions, true);
    }

    /**
     * @return Pagination<Issue>
     */
    public function getIssues(): Pagination
    {
        return $this->issues ??= $this->queries->ask(new SearchIssues(
            viewer: $this->viewer->get(),
            projectId: $this->project?->getId(),
            assigneeId: $this->assigneeId,
            authorId: $this->authorId,
            place: $this->offers('place') ? $this->knownPlaces() : [],
            project: $this->offers('project') ? self::ids($this->projects) : [],
            tracker: null !== $this->tracker ? [(int) $this->tracker->getId()] : ($this->offers('tracker') ? self::ids($this->trackers) : []),
            assignee: $this->offers('assignee') ? self::ids($this->assignees, withZero: true) : [],
            criticality: self::criticalities($this->criticality),
            q: $this->q,
            closed: $this->closed,
            page: $this->page,
            sort: $this->sortColumn(),
            order: 'desc' === $this->order ? \SortDirection::Descending : \SortDirection::Ascending,
            group: $this->grouping(),
        ));
    }

    /**
     * The issues of the page cut into their groups, when the list is grouped.
     *
     * @return list<IssueGroup>
     */
    public function getGroups(): array
    {
        $grouping = $this->grouping();
        if (null === $grouping) {
            return [];
        }
        $issues = $this->getIssues();

        return IssueGroup::split($issues->items, $grouping, $issues->groupTotals, $this->collapsed);
    }

    /**
     * What the list can group by: the project only across projects, the
     * assignee unless the list is the viewer's own.
     *
     * @return list<IssueGrouping>
     */
    public function getGroupings(): array
    {
        return array_values(array_filter(IssueGrouping::cases(), fn (IssueGrouping $grouping): bool => match ($grouping) {
            IssueGrouping::Project => $this->showProject,
            IssueGrouping::Assignee => null === $this->assigneeId,
            default => true,
        }));
    }

    /**
     * The direction a column is sorted in, null when the list is not sorted by it.
     */
    public function sortDirection(string $column): ?string
    {
        return $this->sort === $column ? $this->order : null;
    }

    /**
     * The label of a place of the list, for the header of its group: its
     * workflow's label first when the list mixes workflows.
     */
    public function placeLabel(string $key): string
    {
        foreach ($this->getPlaceChoices() as $choice) {
            if ($choice->key === $key) {
                return $this->mixesWorkflows() ? $choice->workflowLabel.' › '.$choice->label : $choice->label;
            }
        }

        return $key;
    }

    /**
     * Whether the list holds the issues of several workflows, whose places
     * the filter and the groups then name with their workflow.
     */
    public function mixesWorkflows(): bool
    {
        return \count(array_unique(array_map(static fn (PlaceChoice $choice): string => $choice->workflowLabel, $this->getPlaceChoices()))) > 1;
    }

    /**
     * The places offered by the filter: those of the workflows of the list,
     * the tracker's, the project's trackers', every one across projects.
     *
     * @return list<PlaceChoice>
     */
    public function getPlaceChoices(): array
    {
        return $this->placeChoices ??= match (true) {
            null !== $this->tracker => $this->placeCatalog->choices([$this->tracker->getWorkflowId()]),
            null !== $this->project => $this->placeCatalog->choices(array_map(static fn (Tracker $tracker): string => $tracker->getWorkflowId(), $this->projectTrackers())),
            default => $this->placeCatalog->choices(),
        };
    }

    public function hasFilters(): bool
    {
        return [] !== $this->places || [] !== $this->projects || [] !== $this->trackers || [] !== $this->assignees
            || [] !== $this->criticality || '' !== trim($this->q) || $this->closed;
    }

    /**
     * @return list<ProjectSummary>
     */
    public function getProjectChoices(): array
    {
        return $this->offers('project') ? $this->queries->ask(new ListVisibleProjects($this->viewer->get())) : [];
    }

    /**
     * @return list<Tracker>
     */
    public function getTrackerChoices(): array
    {
        return $this->offers('tracker') ? $this->projectTrackers() : [];
    }

    /**
     * @return list<User>
     */
    public function getMemberChoices(): array
    {
        if (null === $this->project || !$this->offers('assignee')) {
            return [];
        }

        return $this->queries->ask(new ListProjectMembers($this->project));
    }

    /**
     * Whether every row of the page is checked.
     */
    public function isPageSelected(): bool
    {
        $ids = $this->pageIds();

        return [] !== $ids && [] === array_diff($ids, self::ids($this->selected));
    }

    /**
     * The selection page for the checked rows, the project's when the list
     * is a project's, which comes back here with the same filters.
     */
    public function getSelectionUrl(): string
    {
        $parameters = ['ids' => self::ids($this->selected)];
        $back = $this->filteredPath();
        if (null !== $back) {
            $parameters['from'] = $back;
        }
        if (null === $this->project) {
            return $this->urls->generate('app_issues_selection', $parameters);
        }

        return $this->urls->generate('app_project_issues_selection', ['workspaceKey' => $this->project->getWorkspace()->getKey(), 'projectKey' => $this->project->getKey(), ...$parameters]);
    }

    /**
     * Checks every row of the page, or unchecks them all when they are.
     */
    #[LiveAction]
    public function togglePage(): void
    {
        $this->selected = $this->isPageSelected() ? [] : $this->pageIds();
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selected = [];
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->places = $this->projects = $this->trackers = $this->assignees = $this->criticality = [];
        $this->q = '';
        $this->closed = false;
        $this->restart();
    }

    #[LiveAction]
    public function turnPage(#[LiveArg] int $page): void
    {
        $this->page = max(1, $page);
        $this->selected = [];
    }

    /**
     * A click on a column header: sorted ascending, then descending, then
     * back to the default order.
     */
    #[LiveAction]
    public function sortBy(#[LiveArg] string $column): void
    {
        if (null === IssueSort::tryFrom($column)) {
            return;
        }
        [$this->sort, $this->order] = match (true) {
            $this->sort !== $column => [$column, 'asc'],
            'asc' === $this->order => [$column, 'desc'],
            default => ['', 'asc'],
        };
        $this->normalize();
        $this->restart();
    }

    /**
     * Folds a group, or unfolds it.
     */
    #[LiveAction]
    public function toggleGroup(#[LiveArg] string $key): void
    {
        $this->collapsed = \in_array($key, $this->collapsed, true)
            ? array_values(array_diff($this->collapsed, [$key]))
            : [...$this->collapsed, $key];
    }

    /**
     * @return list<int>
     */
    private function pageIds(): array
    {
        return array_map(static fn (Issue $issue): int => (int) $issue->getId(), $this->getIssues()->items);
    }

    /**
     * The address of the list with its filters, as the browser shows it.
     */
    private function filteredPath(): ?string
    {
        if (null === $this->listPath) {
            return null;
        }
        $values = [];
        foreach (self::URL_PROPS as $prop) {
            $values[$prop] = match ($this->{$prop}) {
                true => 1,
                false, '', [] => null,
                default => $this->{$prop},
            };
        }
        // What the list shows anyway is left out: the first page, the order of no sorted column.
        if (1 === $this->page) {
            $values['page'] = null;
        }
        if ('' === $this->sort) {
            $values['order'] = null;
        }
        $query = http_build_query(array_filter($values, static fn (mixed $value): bool => null !== $value));

        return $this->listPath.('' === $query ? '' : '?'.$query);
    }

    /**
     * The column the list is sorted by, when it shows it.
     */
    private function sortColumn(): ?IssueSort
    {
        $sort = IssueSort::tryFrom($this->sort);

        return IssueSort::Project === $sort && !$this->showProject ? null : $sort;
    }

    private function grouping(): ?IssueGrouping
    {
        $grouping = IssueGrouping::tryFrom($this->group);

        return \in_array($grouping, $this->getGroupings(), true) ? $grouping : null;
    }

    /**
     * The chosen places that belong to the workflows of the list.
     *
     * @return list<string>
     */
    private function knownPlaces(): array
    {
        return array_values(array_intersect($this->places, array_map(static fn (PlaceChoice $choice): string => $choice->key, $this->getPlaceChoices())));
    }

    /**
     * The trackers of the project the viewer sees, inactive ones included.
     *
     * @return list<Tracker>
     */
    private function projectTrackers(): array
    {
        return $this->projectTrackers ??= null === $this->project ? [] : $this->trackerRepository->findByProject($this->project, includePrivate: $this->viewer->get()->isInternal(), includeInactive: true);
    }

    /**
     * The address of the page the list is on, without its query string;
     * generated from its route, so it holds behind a base path.
     */
    private function pagePath(): ?string
    {
        $request = $this->requests->getMainRequest();
        $route = $request?->attributes->get('_route');
        if (null === $request || !\is_string($route)) {
            return null;
        }
        $parameters = $request->attributes->get('_route_params', []);

        return $this->urls->generate($route, \is_array($parameters) ? $parameters : []);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<Criticality>
     */
    private static function criticalities(array $values): array
    {
        return array_values(array_filter(array_map(static fn (mixed $value): ?Criticality => \is_string($value) ? Criticality::tryFrom($value) : null, $values)));
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<int>
     */
    private static function ids(array $values, bool $withZero = false): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
                $id = (int) $value;
                if ($id > 0 || ($withZero && 0 === $id)) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }
}
