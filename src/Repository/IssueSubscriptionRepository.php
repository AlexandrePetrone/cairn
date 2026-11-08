<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Issue;
use App\Entity\IssueSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IssueSubscription>
 */
class IssueSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssueSubscription::class);
    }

    public function findOne(Issue $issue, User $user): ?IssueSubscription
    {
        return $this->findOneBy(['issue' => $issue, 'user' => $user]);
    }

    /**
     * The active users following the issue, by name. Whether each one may
     * still see it is the caller's to check.
     *
     * @return list<User>
     */
    public function findSubscribers(Issue $issue): array
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->join(IssueSubscription::class, 's', Join::ON, 's.user = u')
            ->where('s.issue = :issue')
            ->andWhere('u.active = true')
            ->orderBy('u.lastName')
            ->addOrderBy('u.firstName')
            ->setParameter('issue', $issue)
            ->getQuery();

        return EntityList::of($query, User::class);
    }
}
