<?php

declare(strict_types=1);

namespace App\Message\Command\Workspace;

/**
 * Deletes an archived workspace for good, with every project it holds
 * (as DeleteProject does each) and the access granted on it. For the
 * administration only. Handled by DeleteWorkspaceHandler.
 */
final readonly class DeleteWorkspace
{
    public function __construct(
        public int $workspaceId,
    ) {
    }
}
