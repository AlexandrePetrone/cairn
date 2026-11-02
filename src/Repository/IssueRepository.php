<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
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
     * Number of issues sitting on a place, across every tracker bound to
     * the workflow.
     */
    public function countOnPlace(string $workflowId, string $placeId): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->join('i.tracker', 't')
            ->andWhere('t.workflowId = :workflow')
            ->andWhere('i.place = :place')
            ->setParameter('workflow', $workflowId)
            ->setParameter('place', $placeId)
            ->getQuery()
            ->getSingleScalarResult();
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
     * closed when given, cleared when null. Touches nothing else: a move
     * is not an activity on the issues.
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

        return (int) $this->onPlace($workflowId, $fromPlaceId)
            ->update()
            ->set('i.place', ':to')
            ->set('i.status', ':status')
            ->setParameter('to', $toPlaceId)
            ->setParameter('status', $toStatus)
            ->getQuery()
            ->execute();
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
