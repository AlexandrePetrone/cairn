<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Makes an issue private or public. An issue of a private tracker stays private. Handled by ChangeVisibilityHandler.
 */
final readonly class ChangeVisibility
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public bool $private,
    ) {
    }
}
