<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use App\Config\Definition\InstanceDefinition;

/**
 * Gives an instance that has no configuration yet a whole one, at once:
 * the starter configuration of a new instance (Config\StarterKit), the
 * one of the development data and of the tests. Never applied over an
 * existing configuration, which changes through its own commands. Handled
 * by InstallConfigurationHandler.
 */
final readonly class InstallConfiguration
{
    public function __construct(
        public InstanceDefinition $definition,
    ) {
    }
}
