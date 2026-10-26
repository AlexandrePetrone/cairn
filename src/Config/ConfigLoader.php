<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\InstanceDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the instance configuration file. The file lives outside the
 * repository and is only read by the sync command, never at runtime: the
 * snapshot in database is the source of truth.
 */
final class ConfigLoader
{
    public function __construct(
        #[Autowire(env: 'resolve:CAIRN_CONFIG')]
        private readonly string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function load(): InstanceDefinition
    {
        if (!is_file($this->path)) {
            throw new ConfigException(\sprintf('Configuration file "%s" not found. Set CAIRN_CONFIG to its path.', $this->path));
        }

        $definition = (static fn (string $path) => require $path)($this->path);

        if (!$definition instanceof InstanceDefinition) {
            throw new ConfigException(\sprintf('Configuration file "%s" must return an instance of %s, got %s.', $this->path, InstanceDefinition::class, get_debug_type($definition)));
        }

        return $definition;
    }
}
