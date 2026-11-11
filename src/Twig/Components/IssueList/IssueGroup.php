<?php

declare(strict_types=1);

namespace App\Twig\Components\IssueList;

use App\Entity\Issue;
use App\Message\Query\Issue\IssueGrouping;

/**
 * The issues of one group on the page of a grouped list, in their order.
 * `total` counts the group on every page; a group cut by the end of a page
 * starts the next one again.
 */
final readonly class IssueGroup
{
    /**
     * @param non-empty-list<Issue> $issues
     */
    public function __construct(
        public string $key,
        public int $total,
        public array $issues,
    ) {
    }

    /**
     * Cuts the issues of a page, in their order, into their consecutive
     * groups.
     *
     * @param list<Issue>        $issues
     * @param array<string, int> $totals group key => issues of the group on every page (Pagination::$groupTotals)
     *
     * @return list<self>
     */
    public static function split(array $issues, IssueGrouping $grouping, array $totals): array
    {
        $groups = [];
        $key = null;
        $members = [];
        foreach ($issues as $issue) {
            $issueKey = $grouping->keyOf($issue);
            if ($issueKey !== $key && [] !== $members) {
                $groups[] = new self((string) $key, $totals[(string) $key] ?? \count($members), $members);
                $members = [];
            }
            $key = $issueKey;
            $members[] = $issue;
        }
        if ([] !== $members) {
            $groups[] = new self((string) $key, $totals[(string) $key] ?? \count($members), $members);
        }

        return $groups;
    }

    /**
     * The issue the group's header is drawn from: its place, its
     * criticality, its assignee, its project.
     */
    public function first(): Issue
    {
        return $this->issues[0];
    }
}
