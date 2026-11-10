<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * Another issue, where a resource names one: the other end of a link.
 */
final readonly class IssueRef
{
    public function __construct(
        public string $key,
        public string $title,
        public bool $closed,
        /** Its page in the application. */
        public string $url,
    ) {
    }
}
