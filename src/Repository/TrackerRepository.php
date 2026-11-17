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
     * Every tracker of a project in display order, private ones included:
     * for the commands and the project configuration, which see them all.
     * A screen asks ListTrackers, which hides them from external users.
     * Archived ones come only when asked for.
     *
     * @return list<Tracker>
     */
    public function findByProject(Project $project, bool $includeArchived = false): array
    {
        $criteria = ['project' => $project];
        if (!$includeArchived) {
            $criteria['archived'] = false;
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
            ->select('IDENTITY(t.workflow) AS workflowId', 'COUNT(t.id) AS trackers')
            ->groupBy('t.workflow')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $row): array => [$row['workflowId'], (int) $row['trackers']], $rows), 1, 0);
    }
}
