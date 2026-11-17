<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * Deletes a workflow no tracker follows, of a project or of a project
 * template, with its actions. Handled by DeleteWorkflowHandler.
 */
final readonly class DeleteWorkflow
{
    public function __construct(
        public string $id,
    ) {
    }
}
