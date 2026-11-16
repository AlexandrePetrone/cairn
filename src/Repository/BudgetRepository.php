<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Budget;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Budget>
 */
class BudgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Budget::class);
    }

    /**
     * The budgets of a project, the oldest first.
     *
     * @return list<Budget>
     */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByName(Project $project, string $name): ?Budget
    {
        return $this->findOneBy(['project' => $project, 'name' => trim($name)]);
    }
}
