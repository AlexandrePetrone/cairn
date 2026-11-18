<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * An action the user may apply to an issue from its place: what POST
 * /issues/{key}/transitions accepts from them.
 */
final readonly class OfferedTransition
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $description,
        /** Whether this user must comment to apply it. */
        public bool $commentRequired,
        /** Where it leads. */
        public ?PlaceRef $to = null,
    ) {
    }
}
