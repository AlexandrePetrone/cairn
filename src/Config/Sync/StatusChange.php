<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Entity\Enum\PlaceStatus;

/**
 * Issues standing on a place whose status is not the one the next
 * definition gives it: an issue keeps a copy of the status of its place,
 * which the sync brings back in step.
 */
final readonly class StatusChange implements \Stringable
{
    public function __construct(
        public string $placeId,
        public PlaceStatus $from,
        public PlaceStatus $to,
        public int $count,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('places.%s: %d issue(s) from status "%s" to "%s"', $this->placeId, $this->count, $this->from->value, $this->to->value);
    }
}
