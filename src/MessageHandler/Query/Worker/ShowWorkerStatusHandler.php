<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Worker;

use App\Entity\PendingNotification;
use App\Message\Query\Worker\ShowWorkerStatus;
use App\Message\Query\Worker\WorkerStatus;
use App\Notification\ProjectMailSender;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads the two queues a worker empties. The messages are in the table of
 * the Doctrine transport (config/packages/messenger.yaml: `async` is its
 * default queue, `failed` the other), whose dates are read and compared
 * through the Doctrine type, as the transport does, never as strings. The
 * mails of the issues wait in PendingNotification for the Scheduler: one
 * is late once it waited longer than ProjectMailSender ever lets a change
 * wait.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ShowWorkerStatusHandler
{
    private const string TABLE = 'messenger_messages';
    private const string ASYNC_QUEUE = 'default';
    private const string FAILED_QUEUE = 'failed';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(ShowWorkerStatus $query): WorkerStatus
    {
        $now = $this->clock->now();
        // The transport writes and compares its dates as UTC objects through this type: so does this.
        $utcNow = $now->setTimezone(new \DateTimeZone('UTC'));
        $connection = $this->entityManager->getConnection();

        // A message put off by a retry is not waiting for a worker yet; one being handled no longer is.
        $waiting = $connection->fetchAssociative(
            'SELECT COUNT(*) AS waiting, MIN(available_at) AS oldest FROM '.self::TABLE.' WHERE queue_name = :queue AND delivered_at IS NULL AND available_at <= :now',
            ['queue' => self::ASYNC_QUEUE, 'now' => $utcNow],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        $oldest = \is_array($waiting) ? $connection->convertToPHPValue($waiting['oldest'] ?? null, Types::DATETIME_IMMUTABLE) : null;
        // Now goes through the type and back as the oldest did, so the age holds whatever timezone the type reads in.
        $nowAsRead = $connection->convertToPHPValue($connection->convertToDatabaseValue($utcNow, Types::DATETIME_IMMUTABLE), Types::DATETIME_IMMUTABLE);

        $failed = $connection->fetchOne('SELECT COUNT(*) FROM '.self::TABLE.' WHERE queue_name = :queue', ['queue' => self::FAILED_QUEUE]);

        $late = $this->entityManager->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(PendingNotification::class, 'n')
            ->where('n.createdAt <= :before')
            ->setParameter('before', $now->modify(\sprintf('-%d seconds', ProjectMailSender::MAX_WAIT + WorkerStatus::LATE)), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return new WorkerStatus(
            waiting: \is_array($waiting) && is_numeric($waiting['waiting'] ?? null) ? (int) $waiting['waiting'] : 0,
            oldestWaiting: $oldest instanceof \DateTimeImmutable && $nowAsRead instanceof \DateTimeImmutable ? max(0, $nowAsRead->getTimestamp() - $oldest->getTimestamp()) : null,
            failed: is_numeric($failed) ? (int) $failed : 0,
            lateNotifications: (int) $late,
        );
    }
}
