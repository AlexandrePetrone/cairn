<?php

declare(strict_types=1);

namespace App\Form\Config;

/**
 * What the dialogs that add a step to a workflow and take one out of it
 * ask: the step to add, or the one that takes the issues of the step that
 * leaves.
 */
final class WorkflowPlaceData
{
    public ?string $place = null;
}
