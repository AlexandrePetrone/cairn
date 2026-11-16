<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates a project template, empty, under a name of its own. Handled by
 * CreateTemplateHandler, which returns the ProjectTemplate.
 */
final readonly class CreateTemplate
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $name,
        public ?string $description = null,
    ) {
    }
}
