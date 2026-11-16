<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adds a tracker to a project template, after the others, bound to a
 * workflow of the configuration for good. Handled by
 * CreateTemplateTrackerHandler, which returns the TemplateTracker.
 */
final readonly class CreateTemplateTracker
{
    public function __construct(
        public int $templateId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $name,
        #[Assert\NotBlank]
        public string $workflowId,
        public ?string $description = null,
        public bool $private = false,
    ) {
    }
}
