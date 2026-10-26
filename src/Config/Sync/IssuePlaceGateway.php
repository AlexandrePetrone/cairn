<?php

declare(strict_types=1);

namespace App\Config\Sync;

/**
 * What the sync needs to know and do about issues sitting on workflow
 * places. Kept behind an interface so the configuration pipeline does not
 * depend on the issue model.
 */
interface IssuePlaceGateway
{
    public function countByPlace(string $workflowId, string $placeId): int;

    /**
     * @return int number of issues moved
     */
    public function movePlace(string $workflowId, string $fromPlaceId, string $toPlaceId): int;
}
