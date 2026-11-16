<?php

declare(strict_types=1);

namespace App\Tests\Form\Issue;

use App\Entity\Enum\Criticality;
use App\Form\Issue\IssueListFilters;
use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\IssueSort;
use PHPUnit\Framework\TestCase;

final class IssueListFiltersTest extends TestCase
{
    public function testAnAddressWithoutParametersIsTheListAsItComes(): void
    {
        $filters = IssueListFilters::fromQuery([]);

        self::assertFalse($filters->hasFilters());
        self::assertSame(1, $filters->page);
        self::assertNull($filters->sort);
        self::assertNull($filters->group);
        self::assertSame(IssueListFilters::VIEW_LIST, $filters->view);
        self::assertSame([], $filters->toQuery());
        self::assertSame('', $filters->queryString());
    }

    public function testItReadsWhatAFormSends(): void
    {
        $filters = IssueListFilters::fromQuery([
            'places' => ['done', 'in_progress'],
            'assignees' => ['0', '12'],
            'criticality' => ['high', 'blocking'],
            'q' => ' cart ',
            'closed' => '1',
            'page' => '3',
            'sort' => 'title',
            'order' => 'desc',
            'group' => 'assignee',
            'view' => 'board',
        ]);

        self::assertSame(['done', 'in_progress'], $filters->places);
        self::assertSame([0, 12], $filters->assignees);
        self::assertSame([Criticality::High, Criticality::Blocking], $filters->criticality);
        self::assertSame('cart', $filters->q);
        self::assertTrue($filters->closed);
        self::assertSame(3, $filters->page);
        self::assertSame(IssueSort::Title, $filters->sort);
        self::assertSame(\SortDirection::Descending, $filters->order);
        self::assertSame(IssueGrouping::Assignee, $filters->group);
        self::assertSame(IssueListFilters::VIEW_BOARD, $filters->view);
        self::assertTrue($filters->hasFilters());
    }

    public function testValuesItDoesNotKnowAreDroppedNeverAnError(): void
    {
        $filters = IssueListFilters::fromQuery([
            'places' => ['done', 'done', 'bug:done', 'not a place', ['nested']],
            'projects' => 'not a list',
            'trackers' => ['4', '4', '0', '-2', 'x'],
            'assignees' => ['0', 'x'],
            'criticality' => ['high', 'unknown'],
            'q' => ['not', 'text'],
            'closed' => 'maybe',
            'page' => '-3',
            'sort' => 'nonsense',
            'order' => 'desc',
            'group' => ['place'],
            'view' => 'calendar',
        ]);

        self::assertSame(['done'], $filters->places, 'a place is named by its id, once');
        self::assertSame([], $filters->projects);
        self::assertSame([4], $filters->trackers, 'only "none" is zero, where a filter has such a choice');
        self::assertSame([0], $filters->assignees);
        self::assertSame([Criticality::High], $filters->criticality);
        self::assertSame('', $filters->q);
        self::assertFalse($filters->closed);
        self::assertSame(1, $filters->page);
        self::assertNull($filters->sort);
        self::assertSame(\SortDirection::Ascending, $filters->order, 'no order without a sorted column');
        self::assertNull($filters->group);
        self::assertSame(IssueListFilters::VIEW_LIST, $filters->view);
    }

    public function testTheAddressOfAnArrangementReadsBackTheSame(): void
    {
        $filters = IssueListFilters::fromQuery(['criticality' => ['high'], 'assignees' => ['0', '12'], 'closed' => '1', 'sort' => 'title', 'order' => 'desc', 'group' => 'place', 'view' => 'board', 'page' => '2']);

        self::assertSame('view=board&criticality%5B%5D=high&assignees%5B%5D=0&assignees%5B%5D=12&closed=1&group=place&sort=title&order=desc&page=2', $filters->queryString(), 'lists as a form sends them');
        parse_str($filters->queryString(), $query);
        self::assertEquals($filters, IssueListFilters::fromQuery($query));
    }

    public function testALinkChangesSomeParametersAndKeepsTheOthers(): void
    {
        $filters = IssueListFilters::fromQuery(['criticality' => ['high'], 'page' => '4']);

        $sorted = $filters->with(['sort' => 'title', 'page' => null]);

        self::assertSame(IssueSort::Title, $sorted->sort);
        self::assertSame(1, $sorted->page);
        self::assertSame([Criticality::High], $sorted->criticality);
    }

    public function testAListRemembersItsArrangementNotThePageNorTheSearch(): void
    {
        $filters = IssueListFilters::fromQuery(['criticality' => ['high'], 'group' => 'place', 'view' => 'board', 'q' => 'cart', 'page' => '2']);

        self::assertSame('view=board&criticality%5B%5D=high&group=place', $filters->remembered()->queryString());
    }
}
