<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * A field the issues of a tracker carry.
 */
final readonly class TrackerField
{
    /**
     * @param array<string, string>|null $options value => label, for the fields that offer a choice
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $type,
        public bool $required,
        public bool $internalOnly,
        public ?array $options = null,
        public ?string $help = null,
    ) {
    }
}
