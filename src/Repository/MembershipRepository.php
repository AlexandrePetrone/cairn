<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Membership;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Membership>
 */
class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /**
     * @return list<Membership>
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    /**
     * The roles granted on a workspace, with their users, by name.
     *
     * @return list<Membership>
     */
    public function findOnWorkspace(Workspace $workspace): array
    {
        /* @var list<Membership> */
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->andWhere('m.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('u.lastName')
            ->addOrderBy('u.firstName')
            ->getQuery()
            ->getResult();
    }

    /**
     * The roles of a user with where they apply, grouped by workspace:
     * the workspace first, then its projects by name.
     *
     * @return list<Membership>
     */
    public function findOfUser(User $user): array
    {
        /* @var list<Membership> */
        return $this->createQueryBuilder('m')
            ->addSelect('w', 'p', 'pw')
            ->leftJoin('m.workspace', 'w')
            ->leftJoin('m.project', 'p')
            ->leftJoin('p.workspace', 'pw')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->addSelect('COALESCE(w.name, pw.name) AS HIDDEN workspaceName')
            ->addSelect('CASE WHEN p.id IS NULL THEN 0 ELSE 1 END AS HIDDEN projectOrder')
            ->orderBy('workspaceName')
            ->addOrderBy('projectOrder')
            ->addOrderBy('p.name')
            ->getQuery()
            ->getResult();
    }
}
