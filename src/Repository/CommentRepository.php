<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Issue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * The last comment written by an internal user whose account is
     * still active.
     */
    public function findLastByInternal(Issue $issue): ?Comment
    {
        /* @var Comment|null */
        return $this->createQueryBuilder('c')
            ->join('c.author', 'u')
            ->andWhere('c.issue = :issue')
            ->andWhere('u.internal = true')
            ->andWhere('u.active = true')
            ->setParameter('issue', $issue)
            ->orderBy('c.id', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
