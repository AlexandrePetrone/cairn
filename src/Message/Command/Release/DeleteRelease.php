<?php

declare(strict_types=1);

namespace App\Message\Command\Release;

/**
 * Deletes a release, whatever its status: the issues it holds are left
 * without a release, which their history says. The actor needs
 * `release.manage`. Handled by DeleteReleaseHandler.
 */
final readonly class DeleteRelease
{
    public function __construct(
        public int $actorId,
        public int $releaseId,
    ) {
    }
}
