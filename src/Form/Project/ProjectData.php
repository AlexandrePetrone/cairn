<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Entity\Project;
use App\Entity\ProjectTemplate;
use App\Entity\Workspace;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the new project form collects; the controller turns it into a
 * CreateProject command.
 */
final class ProjectData
{
    #[Assert\NotNull]
    public ?Workspace $workspace = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Regex(Project::KEY_PATTERN, message: 'admin.key_invalid')]
    public ?string $key = null;

    /** The template the project starts with, if any. */
    public ?ProjectTemplate $template = null;
}
