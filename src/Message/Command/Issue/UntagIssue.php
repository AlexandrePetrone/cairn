<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Takes a tag off an issue; one it does not carry changes nothing. The
 * actor needs `issue.set_tags`. Handled by UntagIssueHandler.
 */
final readonly class UntagIssue
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public int $tagId,
    ) {
    }
}
