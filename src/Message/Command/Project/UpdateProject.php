<?php

declare(strict_types=1);

namespace App\Message\Command\Project;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a project, PATCH style: a property left null keeps its value.
 * An archived project is read-only for everyone. Its key never changes.
 * Handled by UpdateProjectHandler.
 */
final readonly class UpdateProject
{
    public function __construct(
        public int $projectId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $name = null,
        public ?bool $archived = null,
    ) {
    }
}
