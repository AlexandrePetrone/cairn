<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adds a step of the instance to a workflow, as its last column unless a
 * position is given. Handled by AddWorkflowPlaceHandler.
 */
final readonly class AddWorkflowPlace
{
    public function __construct(
        public string $workflowId,
        public string $placeId,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}
