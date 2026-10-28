<?php

declare(strict_types=1);

namespace App\Config\Sync;

/**
 * Issues of a removed place to move to a place of the next definition.
 */
final readonly class PlaceMigration implements \Stringable
{
    public const string BY_RULE = 'migrateFrom rule';
    public const string BY_FALLBACK = 'fallback place';

    /**
     * @param string $reason BY_RULE when the target declares the removed place in `migrateFrom`, BY_FALLBACK otherwise
     */
    public function __construct(
        public string $workflowId,
        public string $from,
        public string $to,
        public int $count,
        public string $reason,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('workflows.%s: %d issue(s) from "%s" to "%s" (%s)', $this->workflowId, $this->count, $this->from, $this->to, $this->reason);
    }
}
