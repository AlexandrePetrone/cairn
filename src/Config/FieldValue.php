<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\FieldDefinition;

/**
 * A filled-in field of an issue as a screen shows it: the definition, and
 * the value with option labels resolved (InstanceDefinition::fieldValues()).
 */
final readonly class FieldValue
{
    public function __construct(
        public FieldDefinition $field,
        public mixed $value,
    ) {
    }
}
