<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Puts a tag of its project on an issue; one it already carries is left
 * as it is. The actor needs `issue.set_tags`. Handled by TagIssueHandler.
 */
final readonly class TagIssue
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public int $tagId,
    ) {
    }
}
