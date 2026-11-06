<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JournalEntry>
 */
class JournalEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalEntry::class);
    }

    /**
     * The entries a comment came with (an action commented at once).
     *
     * @return list<JournalEntry>
     */
    public function findByComment(Comment $comment): array
    {
        return $this->findBy(['comment' => $comment]);
    }

    /**
     * The entries that changed the place of the issue, oldest first.
     * Privacy is not applied: a place is never private, only the comment
     * that came with a transition may be.
     *
     * @return list<JournalEntry>
     */
    public function findPlaceChanges(Issue $issue): array
    {
        /* @var list<JournalEntry> */
        return $this->createQueryBuilder('e')
            ->andWhere('e.issue = :issue')
            ->andWhere('e.type IN (:types)')
            ->setParameter('issue', $issue)
            ->setParameter('types', [JournalEntryType::Transitioned->value, JournalEntryType::TrackerChanged->value, JournalEntryType::Moved->value])
            ->orderBy('e.at', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
