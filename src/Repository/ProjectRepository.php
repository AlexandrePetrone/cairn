<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * The project behind a URL such as `/MD/SPT/...`.
     */
    public function findOneByKeys(string $workspaceKey, string $projectKey): ?Project
    {
        return $this->createQueryBuilder('p')
            ->join('p.workspace', 'w')
            ->andWhere('w.key = :workspaceKey')
            ->andWhere('p.key = :projectKey')
            ->setParameter('workspaceKey', $workspaceKey)
            ->setParameter('projectKey', $projectKey)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
