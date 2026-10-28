<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Family of a workflow place. Shared by every workflow so that cross-category
 * views (lists, filters, stats) can sort and colour issues consistently.
 *
 * Persisted on issues (denormalised from the place) and referenced by the
 * instance configuration.
 */
enum PlaceStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Closed = 'closed';
}
