<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * The workflow of a tracker, where a resource names it.
 */
final readonly class WorkflowRef
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
