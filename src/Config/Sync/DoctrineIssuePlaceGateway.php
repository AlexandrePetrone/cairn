<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Repository\IssueRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Counts and moves issues in bulk through the repository. Entering a
 * closed place closes them, leaving one reopens them.
 */
final class DoctrineIssuePlaceGateway implements IssuePlaceGateway
{
    public function __construct(
        private readonly IssueRepository $issues,
        private readonly ClockInterface $clock,
    ) {
    }

    public function countByPlace(string $workflowId, string $placeId): int
    {
        return $this->issues->countOnPlace($workflowId, $placeId);
    }

    public function movePlace(string $workflowId, string $fromPlaceId, PlaceDefinition $to): int
    {
        $closedAt = PlaceStatus::Closed === $to->status ? $this->clock->now() : null;

        return $this->issues->moveFromPlace($workflowId, $fromPlaceId, $to->id, $to->status, $closedAt);
    }
}
