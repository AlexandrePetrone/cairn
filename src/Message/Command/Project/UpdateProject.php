<?php

declare(strict_types=1);

namespace App\Message\Command\Project;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a project, PATCH style: a property left null keeps its value.
 * Its key never changes. Without an actor, the administration changes it;
 * with one, the actor needs `project.configure`, and archiving stays the
 * administration's: an archived project grants no permission to anyone,
 * so nobody could unarchive it from the project. Handled by
 * UpdateProjectHandler.
 */
final readonly class UpdateProject
{
    public function __construct(
        public int $projectId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $name = null,
        /** An empty string clears it. */
        public ?string $description = null,
        public ?bool $archived = null,
        public ?int $actorId = null,
    ) {
    }
}
