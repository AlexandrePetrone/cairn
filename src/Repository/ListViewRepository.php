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

    /**
     * Drops the views everyone kept of a list that no longer exists, named
     * by the end of its address (the application may live under a base
     * path), and with `below` of every list under it: those of a project.
     */
    public function forget(string $path, bool $below = false): void
    {
        $queryBuilder = $this->createQueryBuilder('v')
            ->delete()
            ->where('v.list LIKE :list')
            ->setParameter('list', '%'.$path);
        if ($below) {
            $queryBuilder->orWhere('v.list LIKE :below')->setParameter('below', '%'.$path.'/%');
        }
        $queryBuilder->getQuery()->execute();
    }
}
