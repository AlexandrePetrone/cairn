<?php

declare(strict_types=1);

namespace App\Message\Command\Project;

/**
 * Deletes a project for good with everything in it: issues, comments,
 * files, history, trackers, releases, access, linked repositories,
 * automation rules and their executions, imports. For the administration
 * only, and only once the project is archived (or its workspace is):
 * archiving is the reversible step, this one is not. Its key is free
 * again. Handled by DeleteProjectHandler.
 */
final readonly class DeleteProject
{
    public function __construct(
        public int $projectId,
    ) {
    }
}
