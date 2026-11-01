<?php

declare(strict_types=1);

namespace App\Message\Command\Workspace;

use App\Entity\Workspace;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates a workspace; its key is fixed for good. Handled by
 * CreateWorkspaceHandler, which returns the Workspace.
 */
final readonly class CreateWorkspace
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $name,
        #[Assert\Regex(Workspace::KEY_PATTERN)]
        public string $key,
    ) {
    }
}
