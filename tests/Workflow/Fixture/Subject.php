<?php

declare(strict_types=1);

namespace App\Tests\Workflow\Fixture;

use App\Config\Definition\PlaceDefinition;
use App\Workflow\HasPlace;

/**
 * The smallest thing a workflow can move: a place, and the definition it
 * was last moved to.
 */
final class Subject implements HasPlace
{
    public ?PlaceDefinition $lastPlace = null;

    public function __construct(
        private ?string $place = null,
    ) {
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function moveTo(PlaceDefinition $place): void
    {
        $this->place = $place->id;
        $this->lastPlace = $place;
    }
}
