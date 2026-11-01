<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
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

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Regex(Project::KEY_PATTERN, message: 'admin.key_invalid')]
    public ?string $key = null;
}
