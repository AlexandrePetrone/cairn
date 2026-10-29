<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Gives the issue to a member of its project, or to nobody. Handled by AssignIssueHandler.
 */
final readonly class AssignIssue
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public ?int $assigneeId,
    ) {
    }
}
