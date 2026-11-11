<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\Tracker;

/**
 * The open issues of a project the viewer sees, counted for each list of
 * its navigation: all of them, those assigned to the viewer, those they
 * created, and those of each tracker.
 */
final readonly class ProjectIssueCounts
{
    /**
     * @param array<int, int> $byTracker tracker id => open issues; a tracker without any is left out
     */
    public function __construct(
        public int $all = 0,
        public int $assigned = 0,
        public int $created = 0,
        public array $byTracker = [],
    ) {
    }

    public function of(Tracker $tracker): int
    {
        return $this->byTracker[(int) $tracker->getId()] ?? 0;
    }
}
