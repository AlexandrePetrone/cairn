<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Moves an issue to another tracker of its project, on a place chosen in
 * the workflow of that tracker, for who moves freely
 * (AccessControl::canMoveFreely()): no action leads from one workflow to
 * another.
 * Handled by ChangeTrackerHandler, which returns the Issue.
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
