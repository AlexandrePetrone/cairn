<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PendingNotification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PendingNotification>
 */
class PendingNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingNotification::class);
    }

    /**
     * Everything that waits, oldest first, with the issue, its project and
     * the people joined. The queue only holds the last minutes of activity,
     * so the caller groups it in memory.
     *
     * @return list<PendingNotification>
     */
    public function findWaiting(): array
    {
        $query = $this->createQueryBuilder('n')
            ->addSelect('i', 'p', 'r', 'a')
            ->join('n.issue', 'i')
            ->join('i.project', 'p')
            ->join('n.recipient', 'r')
            ->join('n.actor', 'a')
            ->orderBy('n.id', 'ASC')
            ->getQuery();

        return EntityList::of($query, PendingNotification::class);
    }

    /**
     * Drops what waited for too long to be worth a mail.
     *
     * @return int how many rows were dropped
     */
    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        $deleted = $this->getEntityManager()->createQueryBuilder()
            ->delete(PendingNotification::class, 'n')
            ->where('n.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }

    /**
     * Deletes the rows at once, in the database: the number it returns is
     * how many were still there, which tells a sender whether another one
     * took them first.
     *
     * @param list<PendingNotification> $rows
     */
    public function delete(array $rows): int
    {
        $deleted = $this->getEntityManager()->createQueryBuilder()
            ->delete(PendingNotification::class, 'n')
            ->where('n.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (PendingNotification $row): ?int => $row->getId(), $rows))
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
