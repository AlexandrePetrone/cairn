<?php

declare(strict_types=1);

namespace App\Twig\Components\IssueList;

use App\Entity\Issue;
use App\Form\Issue\IssueListFilters;
use App\Message\Query\Issue\IssueGrouping;
use App\Message\QueryBus;
use App\Repository\Pagination;
use App\Workflow\PlaceChoice;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The issues of a list as its address arranges them: the rows, cut into
 * groups when the list is grouped, the headers that sort it and the pager,
 * all links to the same list arranged otherwise. Rows are checked in the
 * browser (the `selection` controller) and sent to the selection page,
 * which holds the bulk actions; groups fold in the browser too (`fold`).
 */
#[AsTwigComponent('IssueList:Table')]
final class Table
{
    public const array COLUMNS = ['key', 'project', 'title', 'tracker', 'place', 'criticality', 'assignee', 'updated'];

    public ListScope $scope;

    public IssueListFilters $filters;

    /** @var Pagination<Issue>|null read once per render */
    private ?Pagination $issues = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly ListChoices $choices,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return Pagination<Issue>
     */
    public function getIssues(): Pagination
    {
        return $this->issues ??= $this->queries->ask($this->choices->search($this->scope, $this->filters));
    }

    /**
     * @return list<string> among self::COLUMNS
     */
    public function getColumns(): array
    {
        return array_values(array_filter(self::COLUMNS, fn (string $column): bool => 'project' !== $column || $this->scope->showProject));
    }

    public function getGrouping(): ?IssueGrouping
    {
        return $this->choices->grouping($this->scope, $this->filters);
    }

    /**
     * The issues of the page cut into their groups, when the list is grouped.
     *
     * @return list<IssueGroup>
     */
    public function getGroups(): array
    {
        $grouping = $this->getGrouping();
        if (null === $grouping) {
            return [];
        }
        $issues = $this->getIssues();

        return IssueGroup::split($issues->items, $grouping, $issues->groupTotals);
    }

    /**
     * The direction a column is sorted in, null when the list is not sorted by it.
     */
    public function sortDirection(string $column): ?string
    {
        if ($this->scope->sort($this->filters)?->value !== $column) {
            return null;
        }

        return \SortDirection::Descending === $this->filters->order ? 'desc' : 'asc';
    }

    /**
     * The list sorted by a column: ascending, then descending, then back
     * to the default order.
     */
    public function sortUrl(string $column): string
    {
        return $this->scope->url($this->filters->with(match ($this->sortDirection($column)) {
            null => ['sort' => $column, 'order' => null, 'page' => null],
            'asc' => ['sort' => $column, 'order' => 'desc', 'page' => null],
            default => ['sort' => null, 'order' => null, 'page' => null],
        }));
    }

    public function pageUrl(int $page): string
    {
        return $this->scope->url($this->filters->with(['page' => $page]));
    }

    /**
     * The selection page the checked rows are sent to: the project's when
     * the list is a project's.
     */
    public function getSelectionUrl(): string
    {
        $project = $this->scope->project;

        return null === $project
            ? $this->urls->generate('app_issues_selection')
            : $this->urls->generate('app_project_issues_selection', ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()]);
    }

    /**
     * The address of the list as it shows, which the selection page comes back to.
     */
    public function getListUrl(): string
    {
        return $this->scope->url($this->filters);
    }

    /**
     * The label of a place of the list, for the header of its group: its
     * workflow's label first when the list mixes workflows.
     */
    public function placeLabel(string $key): string
    {
        $choice = array_find($this->choices->places($this->scope), static fn (PlaceChoice $choice): bool => $choice->key === $key);
        if (null === $choice) {
            return $key;
        }

        return $this->choices->mixesWorkflows($this->scope) ? $choice->workflowLabel.' › '.$choice->label : $choice->label;
    }

    /**
     * The label of a release group, for its header; the release is read
     * from the project's, so a group header costs no query.
     */
    public function releaseLabel(string $key): ?string
    {
        foreach ($this->choices->releases($this->scope) as $release) {
            if ((string) $release->getId() === $key) {
                return $release->getName();
            }
        }

        return null;
    }
}
