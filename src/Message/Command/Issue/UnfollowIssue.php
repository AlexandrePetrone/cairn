<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The actor stops following an issue; the next time they comment, are
 * mentioned or assigned, they follow it again. Idempotent. Handled by
 * UnfollowIssueHandler.
 */
final readonly class UnfollowIssue
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
    ) {
    }
}
