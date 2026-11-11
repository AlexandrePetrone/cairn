<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Takes an issue to a place of its workflow, whichever way the actor may:
 * by the action of theirs that leads there when there is one, otherwise
 * outside the workflow (`issue.force_place`). A caller that names the
 * action, or sends a comment with it, dispatches ApplyTransition instead;
 * another tracker is ChangeTracker.
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
