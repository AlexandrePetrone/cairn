<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * A global field used by a workflow, in declaration order.
 */
final readonly class FieldUsage
{
    public function __construct(
        public string $field,
        public bool $required = false,
    ) {
        Definitions::assertId($field, 'fields');
    }
}
