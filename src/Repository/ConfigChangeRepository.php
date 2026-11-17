<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConfigChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfigChange>
 */
class ConfigChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfigChange::class);
    }

    /**
     * @return list<ConfigChange> the latest changes, the most recent first
     */
    public function findLatest(int $limit = 200): array
    {
        return $this->findBy([], ['at' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
