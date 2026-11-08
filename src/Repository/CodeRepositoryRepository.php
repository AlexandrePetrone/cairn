<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CodeRepository;
use App\Entity\Enum\Forge;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CodeRepository>
 */
class CodeRepositoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodeRepository::class);
    }

    /**
     * @return list<CodeRepository>
     */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['forge' => 'ASC', 'path' => 'ASC']);
    }

    /**
     * Every project a repository is linked to: a delivery of that repository
     * concerns each of them.
     *
     * @return list<CodeRepository>
     */
    public function findByPath(Forge $forge, string $path): array
    {
        return $this->findBy(['forge' => $forge, 'path' => CodeRepository::normalizePath($path)]);
    }

    public function findOne(Project $project, Forge $forge, string $path): ?CodeRepository
    {
        return $this->findOneBy(['project' => $project, 'forge' => $forge, 'path' => CodeRepository::normalizePath($path)]);
    }
}
