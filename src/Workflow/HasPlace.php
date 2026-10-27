<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\Definition\PlaceDefinition;

/**
 * An object whose state is a single workflow place, stored as the place id.
 * The state machines built by WorkflowFactory read and move it through
 * these two methods.
 */
interface HasPlace
{
    public function getPlace(): ?string;

    public function moveTo(PlaceDefinition $place): void;
}
