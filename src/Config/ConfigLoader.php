<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\InvalidDefinitionException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the instance configuration file. The file lives outside the
 * repository and is only read by the sync command, never at runtime: the
 * snapshot in database is the source of truth.
 *
 * The structure of the file is the signature of the definition
 * constructors, so PHP itself refuses a file written for another version:
 * a required argument missing, a named argument that no longer exists, a
 * wrong type. Those refusals are reported with the line of the file that
 * caused them, so that the administrator knows what to change.
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

    /**
     * @throws ConfigException when the file is missing, does not load or does not return a definition
     */
    public function load(): InstanceDefinition
    {
        if (!is_file($this->path)) {
            throw new ConfigException(\sprintf('Configuration file "%s" not found. Set CAIRN_CONFIG to its path.', $this->path));
        }

        try {
            $definition = (static fn (string $path) => require $path)($this->path);
        } catch (InvalidDefinitionException|\Error|\ErrorException $e) {
            throw new ConfigException(\sprintf('Configuration file "%s"%s: %s See UPGRADE.md when the file was written for a previous version of Cairn.', $this->path, $this->lineOf($e), rtrim($e->getMessage(), '.').'.'), 0, $e);
        }

        if (!$definition instanceof InstanceDefinition) {
            throw new ConfigException(\sprintf('Configuration file "%s" must return an instance of %s, got %s.', $this->path, InstanceDefinition::class, get_debug_type($definition)));
        }

        return $definition;
    }

    /**
     * The line of the configuration file the error comes from: where it
     * was thrown, or the frame of the file that called the constructor.
     * PHP reports the resolved path, whatever CAIRN_CONFIG says.
     */
    private function lineOf(\Throwable $e): string
    {
        $path = realpath($this->path) ?: $this->path;
        if ($e->getFile() === $path) {
            return \sprintf(' (line %d)', $e->getLine());
        }
        foreach ($e->getTrace() as $frame) {
            if (($frame['file'] ?? null) === $path) {
                return \sprintf(' (line %d)', $frame['line'] ?? 0);
            }
        }

        return '';
    }
}
