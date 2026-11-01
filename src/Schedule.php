<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The default schedule, consumed by `messenger:consume scheduler_default`.
 * Its tasks declare themselves with `#[AsPeriodicTask]` on the service
 * that does the work (Attachment/AttachmentPruner). Stateful, so that a
 * run missed while the worker was down happens once at the next start.
 */
#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
