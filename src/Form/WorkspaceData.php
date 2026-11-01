<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Workspace;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the workspace forms collect: the key at creation only, then the
 * name and whether it is archived. The controller turns it into
 * CreateWorkspace or UpdateWorkspace.
 */
final class WorkspaceData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\NotBlank(groups: ['creation'])]
    #[Assert\Regex(Workspace::KEY_PATTERN, message: 'admin.key_invalid', groups: ['creation'])]
    public ?string $key = null;

    public bool $archived = false;
}
