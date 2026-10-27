<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Config\Definition\PlaceDefinition;

/**
 * What the sync needs to know and do about issues sitting on workflow
 * places. Kept behind an interface so the configuration pipeline can be
 * tested without issues.
 */
interface IssuePlaceGateway
{
    public function countByPlace(string $workflowId, string $placeId): int;

    /**
     * Moves every issue of the workflow sitting on the removed place to the
     * target place, status included.
     *
     * @return int number of issues moved
     */
    public function movePlace(string $workflowId, string $fromPlaceId, PlaceDefinition $to): int;
}
