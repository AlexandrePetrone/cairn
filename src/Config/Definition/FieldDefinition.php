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
        Definitions::assertId($id, 'fields');
        Definitions::assertLabel($label, "fields.$id.label");

        if ($type->hasOptions() && [] === $options) {
            throw new InvalidDefinitionException(\sprintf('fields.%s: a %s field needs options.', $id, $type->value));
        }
        if (!$type->hasOptions() && [] !== $options) {
            throw new InvalidDefinitionException(\sprintf('fields.%s: a %s field cannot have options.', $id, $type->value));
        }
        foreach ($options as $value => $optionLabel) {
            if (!\is_string($value) || '' === $value || !\is_string($optionLabel) || '' === $optionLabel) {
                throw new InvalidDefinitionException(\sprintf('fields.%s.options: options map a string value to a non-empty label.', $id));
            }
        }
    }
}
