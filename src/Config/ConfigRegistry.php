<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\InstanceDefinition;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runtime access to the configuration: the tables, read once per request,
 * and per message for a worker, which resets its services between two
 * messages: no shared cache on purpose, so that a change made anywhere is
 * visible to every process at its next request or message. A command that
 * writes the configuration resets it for the rest of its own.
 */
final class ConfigRegistry implements ResetInterface
{
    private ?InstanceDefinition $definition = null;

    public function __construct(
        private readonly DefinitionLoader $loader,
    ) {
    }

    /**
     * An instance nobody configured yet has an empty definition: no role,
     * no workflow, and pages that say so.
     */
    public function definition(): InstanceDefinition
    {
        return $this->definition ??= $this->loader->load();
    }

    public function reset(): void
    {
        $this->definition = null;
    }
}
