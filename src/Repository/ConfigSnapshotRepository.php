<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConfigSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfigSnapshot>
 */
class ConfigSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfigSnapshot::class);
    }

    public function findLatest(): ?ConfigSnapshot
    {
        return $this->findOneBy([], ['id' => 'DESC']);
    }
}
