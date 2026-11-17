<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * Deletes an action of a workflow. Handled by DeleteTransitionHandler.
 */
final readonly class DeleteTransition implements ConfigurationCommand
{
    public function __construct(
        public string $workflowId,
        public string $id,
    ) {
    }
}
