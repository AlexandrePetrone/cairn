<?php

declare(strict_types=1);

namespace App\Tests\Workflow\Fixture;

use App\Entity\Place;
use App\Workflow\HasPlace;

/**
 * The smallest thing a workflow can move: a place, and the definition it
 * was last moved to.
 */
final class Subject implements HasPlace
{
    public ?Place $lastPlace = null;

    public function __construct(
        private ?string $place = null,
    ) {
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function moveTo(Place $place): void
    {
        $this->place = $place->getId();
        $this->lastPlace = $place;
    }
}
