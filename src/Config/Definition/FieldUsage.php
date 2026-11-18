<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * A global field used by a workflow, at its place in the form.
 */
final readonly class FieldUsage
{
    public function __construct(
        public string $field,
        public bool $required = false,
    ) {
    }
}
