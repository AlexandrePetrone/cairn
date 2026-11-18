<?php

declare(strict_types=1);

namespace App\Config;

/**
 * A whole configuration cannot be installed: the instance already has
 * one, or the one given is not sound. A programming error, not a refusal
 * to show (those are ConfigChangeException).
 */
class ConfigException extends \RuntimeException
{
}
