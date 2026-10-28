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
     * asked for, since external users never see them.
     *
     * @return list<Tracker>
     */
    public function findByProject(Project $project, bool $includePrivate): array
    {
        $criteria = ['project' => $project];
        if (!$includePrivate) {
            $criteria['private'] = false;
        }

        return $this->findBy($criteria, ['position' => 'ASC', 'id' => 'ASC']);
    }
}
