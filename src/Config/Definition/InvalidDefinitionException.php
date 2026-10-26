<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * A definition object was built with inconsistent arguments (duplicate id,
 * empty id, unknown reference...). Thrown at construction, before any
 * cross-validation.
 */
final class InvalidDefinitionException extends \InvalidArgumentException
{
}
