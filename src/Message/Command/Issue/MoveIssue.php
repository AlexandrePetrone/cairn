<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Takes an issue to a place of its workflow, the way the actor may: freely
 * for who moves freely (AccessControl::canMoveFreely()), otherwise by the
 * action of theirs that leads there. A caller that names the action, or
 * sends a comment with it, dispatches ApplyTransition instead; another
 * tracker is ChangeTracker.
 * Handled by MoveIssueHandler, which returns the Issue.
 */
final readonly class MoveIssue
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $placeId,
    ) {
    }
}
