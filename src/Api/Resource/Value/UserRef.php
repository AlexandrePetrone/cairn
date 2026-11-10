<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * A person, where a resource names one.
 */
final readonly class UserRef
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $internal,
        /** For the agency only. */
        public ?string $email = null,
    ) {
    }
}
