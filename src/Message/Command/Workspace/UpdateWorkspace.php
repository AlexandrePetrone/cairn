<?php

declare(strict_types=1);

namespace App\Message\Command\Workspace;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a workspace, PATCH style: a property left null keeps its value.
 * Archiving it makes all of its projects read-only. Its key never
 * changes. Handled by UpdateWorkspaceHandler.
 */
final readonly class UpdateWorkspace
{
    public function __construct(
        public int $workspaceId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $name = null,
        public ?bool $archived = null,
    ) {
    }
}
