<?php

declare(strict_types=1);

namespace App\Config;

/**
 * The configuration cannot be read or installed as it is: a programming
 * or data error, not a refusal to show (those are ConfigChangeException).
 */
class ConfigException extends \RuntimeException
{
}
