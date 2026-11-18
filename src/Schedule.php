<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The default schedule, consumed by `messenger:consume scheduler_default`.
 * Its tasks declare themselves with `#[AsPeriodicTask]` on the service
 * that does the work (Attachment/AttachmentPruner). Stateful, so that a
 * run missed while the worker was down happens once at the next start,
 * and locked, so that several workers run a task once between them: the
 * state and the lock are both in the database.
 */
#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LockFactory $locks,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->lock($this->locks->createLock('cairn.schedule'))
            ->processOnlyLastMissedRun(true);
    }
}
