<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates a workflow with its initial place, so that it never exists
 * without one; its other places, its actions and its fields are added
 * afterwards. Handled by CreateWorkflowHandler, which returns the Workflow.
 */
final readonly class CreateWorkflow implements ConfigurationCommand
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $label,
        #[Assert\NotBlank]
        public string $initial,
    ) {
    }
}
