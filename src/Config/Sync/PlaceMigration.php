<?php

declare(strict_types=1);

namespace App\Config\Sync;

/**
 * Issues of a removed place to move to a place of the next definition.
 */
final readonly class PlaceMigration implements \Stringable
{
    public function __construct(
        public string $workflowId,
        public string $from,
        public string $to,
        public int $count,
        public MigrationReason $reason,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('workflows.%s: %d issue(s) from "%s" to "%s" (%s)', $this->workflowId, $this->count, $this->from, $this->to, $this->reason->value);
    }
}
