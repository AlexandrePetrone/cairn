<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Triggers a workflow transition on an issue, with the comment that may
 * come with it. Handled by ApplyTransitionHandler, which returns the Issue.
 */
final readonly class ApplyTransition
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $transitionId,
        public ?string $comment = null,
        public bool $commentPrivate = false,
    ) {
    }
}
