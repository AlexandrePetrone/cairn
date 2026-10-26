<?php

declare(strict_types=1);

namespace App\Config\Sync;

/**
 * A removed place with no migration target. Blocks the sync when it still
 * holds issues.
 */
final readonly class OrphanPlace implements \Stringable
{
    public function __construct(
        public string $workflowId,
        public string $placeId,
        public int $count,
        public string $reason,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('workflows.%s.places.%s: %d issue(s), %s', $this->workflowId, $this->placeId, $this->count, $this->reason);
    }
}
