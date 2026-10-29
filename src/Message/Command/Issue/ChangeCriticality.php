<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use App\Entity\Enum\Criticality;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes the criticality of an issue. Handled by ChangeCriticalityHandler.
 */
final readonly class ChangeCriticality
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public Criticality $criticality,
    ) {
    }
}
