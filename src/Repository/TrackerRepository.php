<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Tracker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tracker>
 */
class TrackerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tracker::class);
    }

    /**
     * The trackers of a project in display order; private ones only when
     * asked for, since external users never see them, and deactivated ones
     * only for the project configuration.
     *
     * @return list<Tracker>
     */
    public function findByProject(Project $project, bool $includePrivate, bool $includeInactive = false): array
    {
        $criteria = ['project' => $project];
        if (!$includePrivate) {
            $criteria['private'] = false;
        }
        if (!$includeInactive) {
            $criteria['active'] = true;
        }

        return $this->findBy($criteria, ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return array<string, int> workflow id => trackers bound to it
     */
    public function countByWorkflow(): array
    {
        /** @var list<array{workflowId: string, trackers: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.workflowId AS workflowId', 'COUNT(t.id) AS trackers')
            ->groupBy('t.workflowId')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $row): array => [$row['workflowId'], (int) $row['trackers']], $rows), 1, 0);
    }
}
