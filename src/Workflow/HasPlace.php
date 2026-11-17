<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Place;

/**
 * An object whose state is a single workflow place: a row of the
 * configuration, read by its key.
 * The state machines built by WorkflowFactory read and move it through
 * these two methods.
 */
interface HasPlace
{
    public function getPlace(): ?string;

    public function moveTo(Place $place): void;
}
