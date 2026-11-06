<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Puts an issue on a place outside the workflow's actions: on any place of
 * its own workflow (`issue.force_place`), or in another tracker of the same
 * project on a place chosen in the new workflow (`issue.change_tracker`).
 * Handled by MoveIssueHandler, which returns the Issue.
 */
final readonly class MoveIssue
{
    /**
     * @param int|null $trackerId the tracker to move to; null, or the current tracker, keeps the
     *                            issue in it and only forces the place
     */
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public ?int $trackerId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $placeId,
    ) {
    }
}
