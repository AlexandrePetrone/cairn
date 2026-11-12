<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Places an issue in its kanban column, next to the cards the actor saw
 * around where they dropped it (`issue.rank`): right under `aboveKey`
 * when it is given, otherwise right over `belowKey`. Both are issues of
 * the same tracker on the same place; with neither, the issue stays where
 * it is. The order of a column is everyone's; ranking is not a change of
 * the issue: no journal entry, no notification.
 * Handled by RankIssueHandler.
 */
final readonly class RankIssue
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public ?string $aboveKey = null,
        public ?string $belowKey = null,
    ) {
    }
}
