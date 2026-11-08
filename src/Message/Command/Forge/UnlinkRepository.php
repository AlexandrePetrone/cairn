<?php

declare(strict_types=1);

namespace App\Message\Command\Forge;

/**
 * Removes a repository from a project: its webhook is removed from the
 * forge when it was installed through the API, then the link is deleted.
 * The code references it produced stay. Handled by UnlinkRepositoryHandler.
 */
final readonly class UnlinkRepository
{
    public function __construct(
        public int $codeRepositoryId,
        public ?int $actorId = null,
    ) {
    }
}
