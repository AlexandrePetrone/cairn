<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The actor follows an issue they see: they will be told by mail what
 * happens to it. Idempotent. Handled by FollowIssueHandler.
 */
final readonly class FollowIssue
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
    ) {
    }
}
