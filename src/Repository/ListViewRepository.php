<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ListView;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ListView>
 */
class ListViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListView::class);
    }

    /**
     * The view the user keeps of a list, named by its address.
     */
    public function findOne(User $user, string $list): ?ListView
    {
        return $this->findOneBy(['user' => $user, 'list' => $list]);
    }
}
