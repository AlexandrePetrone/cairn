<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * Takes a place out of a workflow. The issues standing on it, in the
 * trackers that follow the workflow, go to `moveIssuesTo`, another of its
 * places, without a word in their history; the actions that led to the
 * place go with it, and those that started from it start from their other
 * places, or go too when they had none. Handled by
 * RemoveWorkflowPlaceHandler.
 */
final readonly class RemoveWorkflowPlace
{
    public function __construct(
        public string $workflowId,
        public string $placeId,
        public ?string $moveIssuesTo = null,
    ) {
    }
}
