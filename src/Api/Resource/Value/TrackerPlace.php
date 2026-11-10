<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * A step of the workflow of a tracker.
 */
final readonly class TrackerPlace
{
    public function __construct(
        public string $id,
        /** What the `place` filter of GET /issues takes: the step named with its workflow. */
        public string $key,
        public string $label,
        /** Where a new issue starts. */
        public bool $initial,
        public bool $closed,
    ) {
    }
}
