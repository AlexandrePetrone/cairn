<?php

declare(strict_types=1);

namespace App\Workflow;

/**
 * An object whose state is a single workflow place, stored as the place id.
 * The accessors are the ones Symfony's MethodMarkingStore calls for a
 * single-state marking on the `place` property.
 */
interface HasPlace
{
    public function getPlace(): ?string;

    /**
     * @param array<string, mixed> $context context given to Workflow::apply()
     */
    public function setPlace(string $place, array $context = []): void;
}
