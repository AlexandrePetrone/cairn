<?php

declare(strict_types=1);

namespace App\Tests\Workflow\Fixture;

use App\Workflow\HasPlace;

/**
 * The smallest thing a workflow can move: a place and the context of the
 * last move.
 */
final class Subject implements HasPlace
{
    /** @var array<string, mixed> */
    public array $lastContext = [];

    public function __construct(
        private ?string $place = null,
    ) {
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(string $place, array $context = []): void
    {
        $this->place = $place;
        $this->lastContext = $context;
    }
}
