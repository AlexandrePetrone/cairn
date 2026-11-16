<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a project template, PATCH style: a property left null keeps
 * its value, an empty description erases it. Handled by
 * UpdateTemplateHandler.
 */
final readonly class UpdateTemplate
{
    public function __construct(
        public int $templateId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $name = null,
        public ?string $description = null,
    ) {
    }
}
