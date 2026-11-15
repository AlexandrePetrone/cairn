<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Deletes an issue for good, with its comments, files, links, history and
 * followers: for what should never have been written (a test, a
 * duplicate filed by mistake, a secret pasted in a description). Its
 * number is not given again. The actor needs `issue.delete`. Handled by
 * DeleteIssueHandler.
 */
final readonly class DeleteIssue
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank]
        public string $issueKey,
    ) {
    }
}
