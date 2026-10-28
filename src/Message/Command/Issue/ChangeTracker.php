<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Moves an issue to another tracker of the same project, on a place chosen
 * in the new workflow. Handled by ChangeTrackerHandler, which returns the
 * Issue.
 */
final readonly class ChangeTracker
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public int $trackerId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $placeId,
    ) {
    }
}
