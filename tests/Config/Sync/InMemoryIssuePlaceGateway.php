<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\Definition\PlaceDefinition;
use App\Config\Sync\IssuePlaceGateway;

/**
 * Counts issues per place in memory and records the moves it is asked for.
 */
final class InMemoryIssuePlaceGateway implements IssuePlaceGateway
{
    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var list<array{string, string, string, int}> workflow, from, to, count */
    public array $moves = [];

    public function set(string $workflowId, string $placeId, int $count): void
    {
        $this->counts[$workflowId][$placeId] = $count;
    }

    public function countByPlace(string $workflowId, string $placeId): int
    {
        return $this->counts[$workflowId][$placeId] ?? 0;
    }

    public function movePlace(string $workflowId, string $fromPlaceId, PlaceDefinition $to): int
    {
        $moved = $this->countByPlace($workflowId, $fromPlaceId);
        $this->counts[$workflowId][$to->id] = $this->countByPlace($workflowId, $to->id) + $moved;
        $this->counts[$workflowId][$fromPlaceId] = 0;
        $this->moves[] = [$workflowId, $fromPlaceId, $to->id, $moved];

        return $moved;
    }
}
