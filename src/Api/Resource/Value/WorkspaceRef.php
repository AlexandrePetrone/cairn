<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

use App\Entity\Workspace;

/**
 * The workspace of a project.
 */
final readonly class WorkspaceRef
{
    public function __construct(
        public string $key,
        public string $name,
    ) {
    }

    public static function of(Workspace $workspace): self
    {
        return new self($workspace->getKey(), $workspace->getName());
    }
}
