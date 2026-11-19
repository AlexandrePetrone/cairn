<?php

declare(strict_types=1);

namespace App\Message\Query\Worker;

/**
 * What the worker has left to do, read from its queues: nothing here is
 * told by the worker itself, so a worker that never ran shows as well as
 * one that stopped.
 */
final readonly class WorkerStatus
{
    /** Seconds a worker that runs never leaves something waiting for. */
    public const int LATE = 300;

    /**
     * @param int      $waiting           messages the `async` transport holds that could be handled now
     * @param int|null $oldestWaiting     seconds the oldest of them has been waiting
     * @param int      $failed            messages given up after their retries
     * @param int      $lateNotifications changes whose mail should have left
     */
    public function __construct(
        public int $waiting,
        public ?int $oldestWaiting,
        public int $failed,
        public int $lateNotifications,
    ) {
    }

    /** Nobody consumes `async`: mails, forge deliveries, imports and the real time signal wait. */
    public function messagesLate(): bool
    {
        return null !== $this->oldestWaiting && $this->oldestWaiting >= self::LATE;
    }

    /** Nobody consumes `scheduler_default`: no issue mail leaves, no time rule runs. */
    public function notificationsLate(): bool
    {
        return $this->lateNotifications > 0;
    }
}
