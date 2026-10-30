<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rewrites the description of an issue. Handled by ChangeDescriptionHandler.
 */
final readonly class ChangeDescription
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public string $description,
    ) {
    }
}
