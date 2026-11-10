<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * A field of an issue that holds a value.
 */
final readonly class IssueField
{
    public function __construct(
        public string $id,
        public string $label,
        /** What the issue stores, and what a write takes. */
        public mixed $value,
        /** What a person reads: the label of an option. */
        public mixed $display,
    ) {
    }
}
