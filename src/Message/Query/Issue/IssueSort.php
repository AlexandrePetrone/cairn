<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

/**
 * A column an issue list can be sorted by (SearchIssues::$sort); without
 * one, the list keeps its default order (Repository/IssueOrder).
 */
enum IssueSort: string
{
    case Key = 'key';
    case Title = 'title';
    case Project = 'project';
    case Tracker = 'tracker';
    case Place = 'place';
    case Criticality = 'criticality';
    case Assignee = 'assignee';
    case Updated = 'updated';
    /** The order of a kanban column (Issue::$boardRank), descending for the top first; no table offers it. */
    case Rank = 'rank';
}
