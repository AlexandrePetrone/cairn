<?php

declare(strict_types=1);

namespace App\Repository;

use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\IssueSort;
use Doctrine\ORM\QueryBuilder;

/**
 * The order of every issue list: the groups first when the list is
 * grouped, then the column it is sorted by, then the default order:
 * status (open, in progress, waiting, closed), criticality (blocking
 * first), latest activity. The query builder names the issue `i`, its
 * tracker `t`, project `p`, workspace `w`, assignee `a` and release `r`.
 */
final class IssueOrder
{
    /** Open first, then in progress, waiting, closed. */
    private const string STATUS_RANK = "CASE i.status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'waiting' THEN 3 ELSE 4 END";

    /** Blocking first, then high, normal, low. */
    private const string CRITICALITY_RANK = "CASE i.criticality WHEN 'blocking' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END";

    /**
     * @param array<string, int> $placeRanks place id => rank, in the order of the instance (InstanceDefinition::$places), to sort or group by place
     */
    public static function apply(QueryBuilder $queryBuilder, ?IssueGrouping $group = null, ?IssueSort $sort = null, \SortDirection $order = \SortDirection::Ascending, array $placeRanks = []): QueryBuilder
    {
        if (null !== $group) {
            match ($group) {
                IssueGrouping::Place => self::byPlace($queryBuilder, 'group', $placeRanks, \SortDirection::Ascending),
                IssueGrouping::Criticality => self::by($queryBuilder, 'groupCriticality', self::CRITICALITY_RANK, \SortDirection::Ascending),
                IssueGrouping::Assignee => self::byAssignee($queryBuilder, 'group', \SortDirection::Ascending),
                IssueGrouping::Project => $queryBuilder->addOrderBy('w.name')->addOrderBy('p.name')->addOrderBy('p.id'),
                IssueGrouping::Release => self::byRelease($queryBuilder),
            };
        }

        if (null !== $sort) {
            $direction = self::sql($order);
            match ($sort) {
                IssueSort::Key => $queryBuilder->addOrderBy('p.key', $direction)->addOrderBy('i.number', $direction),
                IssueSort::Title => self::by($queryBuilder, 'sortTitle', 'LOWER(i.title)', $order),
                IssueSort::Project => $queryBuilder->addOrderBy('w.name', $direction)->addOrderBy('p.name', $direction),
                IssueSort::Tracker => $queryBuilder->addOrderBy('t.name', $direction),
                IssueSort::Place => self::byPlace($queryBuilder, 'sort', $placeRanks, $order),
                // Ascending is the least critical first: the rank read backwards.
                IssueSort::Criticality => self::by($queryBuilder, 'sortCriticality', self::CRITICALITY_RANK, \SortDirection::Ascending === $order ? \SortDirection::Descending : \SortDirection::Ascending),
                IssueSort::Assignee => self::byAssignee($queryBuilder, 'sort', $order),
                IssueSort::Updated => $queryBuilder->addOrderBy('i.updatedAt', $direction),
                IssueSort::Rank => $queryBuilder->addOrderBy('i.boardRank', $direction),
            };
        }

        self::by($queryBuilder, 'statusOrder', self::STATUS_RANK, \SortDirection::Ascending);

        return self::by($queryBuilder, 'criticalityOrder', self::CRITICALITY_RANK, \SortDirection::Ascending)
            ->addOrderBy('i.updatedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC');
    }

    /**
     * The expression whose value is the key of an issue's group, as
     * IssueGrouping::keyOf() gives it.
     */
    public static function groupKey(IssueGrouping $group): string
    {
        return match ($group) {
            IssueGrouping::Place => 'IDENTITY(i.place)',
            IssueGrouping::Criticality => 'i.criticality',
            IssueGrouping::Assignee => 'IDENTITY(i.assignee)',
            IssueGrouping::Project => 'p.id',
            IssueGrouping::Release => 'IDENTITY(i.release)',
        };
    }

    /**
     * The direction as DQL writes it.
     */
    private static function sql(\SortDirection $order): string
    {
        return \SortDirection::Descending === $order ? 'DESC' : 'ASC';
    }

    private static function by(QueryBuilder $queryBuilder, string $alias, string $expression, \SortDirection $order): QueryBuilder
    {
        return $queryBuilder
            ->addSelect(\sprintf('%s AS HIDDEN %s', $expression, $alias))
            ->addOrderBy($alias, self::sql($order));
    }

    /**
     * Unassigned issues first, then by name.
     */
    private static function byAssignee(QueryBuilder $queryBuilder, string $role, \SortDirection $order): QueryBuilder
    {
        return self::by($queryBuilder, $role.'Assigned', 'CASE WHEN a.id IS NULL THEN 0 ELSE 1 END', $order)
            ->addOrderBy('a.lastName', self::sql($order))
            ->addOrderBy('a.firstName', self::sql($order))
            ->addOrderBy('a.id', self::sql($order));
    }

    /**
     * The open releases by planned date, undated ones after, then the
     * delivered and abandoned ones, then the issues in no release.
     */
    private static function byRelease(QueryBuilder $queryBuilder): QueryBuilder
    {
        return self::by($queryBuilder, 'groupReleaseState', "CASE WHEN r.id IS NULL THEN 2 WHEN r.status = 'open' THEN 0 ELSE 1 END", \SortDirection::Ascending)
            ->addSelect('CASE WHEN r.plannedOn IS NULL THEN 1 ELSE 0 END AS HIDDEN groupReleaseUndated')
            ->addOrderBy('groupReleaseUndated', 'ASC')
            ->addOrderBy('r.plannedOn', 'ASC')
            ->addOrderBy('r.id', 'ASC');
    }

    /**
     * @param string             $role       `group` or `sort`, which names its column: a list may group and sort by place
     * @param array<string, int> $placeRanks
     */
    private static function byPlace(QueryBuilder $queryBuilder, string $role, array $placeRanks, \SortDirection $order): QueryBuilder
    {
        if ([] === $placeRanks) {
            return $queryBuilder->addOrderBy('i.place', self::sql($order));
        }

        $cases = '';
        $index = 0;
        foreach ($placeRanks as $placeId => $rank) {
            $cases .= \sprintf(' WHEN i.place = :placeRank%d THEN %d', $index, $rank);
            $queryBuilder->setParameter('placeRank'.$index, $placeId);
            ++$index;
        }

        return self::by($queryBuilder, $role.'Place', 'CASE'.$cases.' ELSE 0 END', $order);
    }
}
