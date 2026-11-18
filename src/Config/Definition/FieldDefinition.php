<?php

declare(strict_types=1);

namespace App\Config\Definition;

use App\Config\FieldType;

/**
 * A global field. Workflows pick which fields they use and whether they are
 * required; values of a field no longer used are kept but hidden.
 */
final readonly class FieldDefinition
{
    /**
     * @param array<string, string> $options      value => label, for Select and Choice
     * @param bool                  $internalOnly never shown to external users
     */
    public function __construct(
        public string $id,
        public string $label,
        public FieldType $type,
        public array $options = [],
        public ?string $placeholder = null,
        public ?string $help = null,
        public bool $internalOnly = false,
    ) {
    }

    /**
     * What a stored value looks like: the label of an option, the value
     * itself otherwise.
     */
    public function display(mixed $value): mixed
    {
        return $this->type->hasOptions() && \is_string($value) ? ($this->options[$value] ?? $value) : $value;
    }
}
