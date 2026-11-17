<?php

declare(strict_types=1);

namespace App\Form\Config;

/**
 * What the dialog that takes a step out of a workflow asks: the step
 * that takes its issues.
 */
final class WorkflowPlaceData
{
    public ?string $place = null;
}
