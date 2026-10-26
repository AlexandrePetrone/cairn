<?php

declare(strict_types=1);

namespace App\Config\Sync;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Placeholder until the issue model exists: there are no issues to count or
 * move. To be replaced by a Doctrine implementation alongside the Issue
 * entity.
 */
#[AsAlias(IssuePlaceGateway::class)]
final class NoIssuesGateway implements IssuePlaceGateway
{
    public function countByPlace(string $workflowId, string $placeId): int
    {
        return 0;
    }

    public function movePlace(string $workflowId, string $fromPlaceId, string $toPlaceId): int
    {
        return 0;
    }
}
