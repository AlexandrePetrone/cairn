<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * What a resource names by its id and its name: a tracker, a release.
 */
final readonly class NamedRef
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
