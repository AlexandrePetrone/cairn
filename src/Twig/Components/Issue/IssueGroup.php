<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

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
        public bool $collapsed,
    ) {
    }

    /**
     * Cuts the issues of a page, in their order, into their consecutive
     * groups.
     *
     * @param list<Issue>        $issues
     * @param array<string, int> $totals    group key => issues of the group on every page (Pagination::$groupTotals)
     * @param list<string>       $collapsed keys of the folded groups
     *
     * @return list<self>
     */
    public static function split(array $issues, IssueGrouping $grouping, array $totals, array $collapsed): array
    {
        $groups = [];
        $key = null;
        $members = [];
        foreach ($issues as $issue) {
            $issueKey = $grouping->keyOf($issue);
            if ($issueKey !== $key && [] !== $members) {
                $groups[] = new self((string) $key, $totals[(string) $key] ?? \count($members), $members, \in_array($key, $collapsed, true));
                $members = [];
            }
            $key = $issueKey;
            $members[] = $issue;
        }
        if ([] !== $members) {
            $groups[] = new self((string) $key, $totals[(string) $key] ?? \count($members), $members, \in_array($key, $collapsed, true));
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
