<?php

declare(strict_types=1);

namespace App\Message\Query\Config;

use App\Entity\Tracker;

/**
 * The trackers of a workflow, with their project and workspace, and their
 * issues counted by place.
 */
final readonly class WorkflowUsage
{
    /**
     * @param list<Tracker>                  $trackers
     * @param array<int, array<string, int>> $issueCounts tracker id => place id => issues
     */
    public function __construct(
        public array $trackers,
        public array $issueCounts,
    ) {
    }

    public function count(Tracker $tracker, string $placeId): int
    {
        return $this->issueCounts[(int) $tracker->getId()][$placeId] ?? 0;
    }

    public function total(Tracker $tracker): int
    {
        return array_sum($this->issueCounts[(int) $tracker->getId()] ?? []);
    }
}
