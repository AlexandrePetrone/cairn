<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * Something was asked of a definition that it does not have (an unknown
 * role, place, workflow…), or it was given two elements of one id. A
 * programming error: whether a configuration is sound is ConfigValidator's
 * to say, not the definition's.
 */
final class InvalidDefinitionException extends \InvalidArgumentException
{
}
