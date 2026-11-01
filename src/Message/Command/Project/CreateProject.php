<?php

declare(strict_types=1);

namespace App\Message\Command\Project;

use App\Entity\Project;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates a project in a workspace, under a key unique in that workspace.
 * Handled by CreateProjectHandler, which returns the Project.
 */
final readonly class CreateProject
{
    public function __construct(
        public int $workspaceId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $name,
        #[Assert\Regex(Project::KEY_PATTERN)]
        public string $key,
    ) {
    }
}
