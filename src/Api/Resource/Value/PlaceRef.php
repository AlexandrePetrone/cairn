<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * A step, where a resource names one.
 */
final readonly class PlaceRef
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
