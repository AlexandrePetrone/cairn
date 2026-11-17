<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Message\Query\Issue\IssueSort;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Issue>
 */
class IssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Issue::class);
    }

    public function findOneByKey(string $key): ?Issue
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Every place that holds issues, with how many, across every workflow.
     *
     * @return list<array{workflowId: string, placeId: string, issues: int}>
     */
    public function countByPlace(): array
    {
        /** @var list<array{workflowId: string, placeId: string, issues: int|string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('t.workflowId AS workflowId', 'i.place AS placeId', 'COUNT(i.id) AS issues')
            ->join('i.tracker', 't')
            ->groupBy('t.workflowId', 'i.place')
            ->orderBy('t.workflowId')
            ->addOrderBy('i.place')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => ['workflowId' => $row['workflowId'], 'placeId' => $row['placeId'], 'issues' => (int) $row['issues']], $rows);
    }

    /**
     * How many issues stand on a place, in the trackers of one workflow or
     * in all of them: what must move before the place leaves.
     */
    public function countOnPlace(string $placeId, ?string $workflowId = null): int
    {
        $count = 0;
        foreach ($this->countByPlace() as $row) {
            if ($row['placeId'] === $placeId && (null === $workflowId || $row['workflowId'] === $workflowId)) {
                $count += $row['issues'];
            }
        }

        return $count;
    }

    /**
     * Gives the issues of a place the status the place now has, in bulk,
     * whatever their workflow. Those that become closed are closed at
     * `$closedAt`, those that no longer are lose their closing date.
     * Touches nothing else: it is not an activity on the issues.
     *
     * @return int number of issues changed
     */
    public function alignStatus(string $placeId, PlaceStatus $status, \DateTimeImmutable $closedAt): int
    {
        $update = $this->createQueryBuilder('i')
            ->update()
            ->set('i.status', ':status')
            ->andWhere('i.place = :place')
            ->andWhere('i.status != :status')
            ->setParameter('place', $placeId)
            ->setParameter('status', $status);

        if (PlaceStatus::Closed === $status) {
            $update->set('i.closedAt', ':closedAt')->setParameter('closedAt', $closedAt);
        } else {
            $update->set('i.closedAt', 'NULL');
        }

        return (int) $update->getQuery()->execute();
    }

    /**
     * Number of issues in each tracker of a project, private ones
     * included: the project configuration is for internal users.
     *
     * @return array<int, int> tracker id => issues
     */
    public function countByTracker(Project $project): array
    {
        /** @var list<array{trackerId: int|string, issues: int|string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.tracker) AS trackerId', 'COUNT(i.id) AS issues')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project)
            ->groupBy('i.tracker')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['trackerId']] = (int) $row['issues'];
        }

        return $counts;
    }

    /**
     * Bulk move of the issues sitting on a place, with the status that
     * goes with the target place. `closedAt` is set on issues not yet
     * closed when given, cleared when null. The kanban columns that took
     * them are ranked anew, the newcomers among the cards already there by
     * the rank they had: two columns count their ranks alike, so merged
     * they would hold equal ones. Touches nothing else: a move is not an
     * activity on the issues.
     *
     * @return int number of issues moved
     */
    public function moveFromPlace(string $workflowId, string $fromPlaceId, string $toPlaceId, PlaceStatus $toStatus, ?\DateTimeImmutable $closedAt): int
    {
        $closing = $this->onPlace($workflowId, $fromPlaceId)->update();
        if (null === $closedAt) {
            $closing->set('i.closedAt', 'NULL');
        } else {
            $closing->set('i.closedAt', ':closedAt')->setParameter('closedAt', $closedAt)->andWhere('i.closedAt IS NULL');
        }
        $closing->getQuery()->execute();

        $trackerIds = $this->onPlace($workflowId, $fromPlaceId)->select('DISTINCT IDENTITY(i.tracker)')->getQuery()->getSingleColumnResult();

        $moved = (int) $this->onPlace($workflowId, $fromPlaceId)
            ->update()
            ->set('i.place', ':to')
            ->set('i.status', ':status')
            ->setParameter('to', $toPlaceId)
            ->setParameter('status', $toStatus)
            ->getQuery()
            ->execute();

        foreach ($trackerIds as $trackerId) {
            $this->spreadRanks($this->getEntityManager()->getReference(Tracker::class, (int) $trackerId) ?? throw new \LogicException('A tracker that holds issues exists.'), $toPlaceId);
        }

        return $moved;
    }

    /**
     * The highest rank of a kanban column, whoever looks; null when the
     * column is empty.
     */
    public function topRank(Tracker $tracker, string $place): ?int
    {
        $rank = $this->inColumn($tracker, $place)->select('MAX(i.boardRank)')->getQuery()->getSingleScalarResult();

        return null === $rank ? null : (int) $rank;
    }

    /**
     * The rank of what stands right under an issue in its column, the
     * issue being placed left aside; null at the bottom.
     */
    public function rankBelow(Issue $neighbour, Issue $placed): ?int
    {
        $rank = $this->inColumn($neighbour->getTracker(), $neighbour->getPlace())
            ->select('MAX(i.boardRank)')
            ->andWhere('i.boardRank < :rank')
            ->andWhere('i != :placed')
            ->setParameter('rank', $neighbour->getBoardRank())
            ->setParameter('placed', $placed)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $rank ? null : (int) $rank;
    }

    /**
     * The rank of what stands right over an issue in its column, the
     * issue being placed left aside; null at the top.
     */
    public function rankAbove(Issue $neighbour, Issue $placed): ?int
    {
        $rank = $this->inColumn($neighbour->getTracker(), $neighbour->getPlace())
            ->select('MIN(i.boardRank)')
            ->andWhere('i.boardRank > :rank')
            ->andWhere('i != :placed')
            ->setParameter('rank', $neighbour->getBoardRank())
            ->setParameter('placed', $placed)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $rank ? null : (int) $rank;
    }

    /**
     * Whether another issue of the column stands at the very rank of this
     * one, the issue being placed left aside: nothing then says which of
     * them is right under or over it.
     */
    public function sharesRank(Issue $neighbour, Issue $placed): bool
    {
        return 0 < (int) $this->inColumn($neighbour->getTracker(), $neighbour->getPlace())
            ->select('COUNT(i.id)')
            ->andWhere('i.boardRank = :rank')
            ->andWhere('i NOT IN (:aside)')
            ->setParameter('rank', $neighbour->getBoardRank())
            ->setParameter('aside', [$neighbour, $placed])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ranks a kanban column anew, a step between each issue, in the order
     * it shows (IssueOrder): what is done when two issues stand too close
     * for a third to fit between them, or at the same rank. Entities already loaded keep their
     * former rank until refreshed.
     */
    public function spreadRanks(Tracker $tracker, string $place): void
    {
        $ids = IssueOrder::apply($this->inColumn($tracker, $place)->select('i.id'), sort: IssueSort::Rank, order: \SortDirection::Descending)
            ->getQuery()
            ->getSingleColumnResult();

        $connection = $this->getEntityManager()->getConnection();
        $table = $this->getClassMetadata()->getTableName();
        foreach (array_reverse($ids) as $index => $id) {
            $connection->update($table, ['board_rank' => $index * Issue::RANK_STEP], ['id' => $id]);
        }
    }

    private function inColumn(Tracker $tracker, string $place): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tracker = :tracker')
            ->andWhere('i.place = :place')
            ->setParameter('tracker', $tracker)
            ->setParameter('place', $place);
    }

    private function onPlace(string $workflowId, string $placeId): QueryBuilder
    {
        $trackers = $this->getEntityManager()->createQueryBuilder()
            ->select('t.id')
            ->from(Tracker::class, 't')
            ->where('t.workflowId = :workflow');

        return $this->createQueryBuilder('i')
            ->andWhere('i.place = :place')
            ->andWhere('i.tracker IN ('.$trackers->getDQL().')')
            ->setParameter('workflow', $workflowId)
            ->setParameter('place', $placeId);
    }
}
