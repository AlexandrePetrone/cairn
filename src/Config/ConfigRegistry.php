<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\InstanceDefinition;
use App\Repository\ConfigSnapshotRepository;

/**
 * Runtime access to the applied configuration: the latest snapshot, decoded
 * once per process. No shared cache on purpose, so that a sync run anywhere
 * is visible to every process at its next request.
 */
final class ConfigRegistry
{
    private ?InstanceDefinition $definition = null;

    public function __construct(
        private readonly ConfigSnapshotRepository $snapshots,
        private readonly SnapshotCodec $codec,
    ) {
    }

    public function definition(): InstanceDefinition
    {
        if (null === $this->definition) {
            $snapshot = $this->snapshots->findLatest()
                ?? throw new ConfigException('No configuration snapshot has been applied yet. Run "app:config:sync" first.');
            try {
                $this->definition = $this->codec->decode($snapshot->getDefinition());
            } catch (ConfigException $e) {
                throw new ConfigException($e->getMessage().' This version changed the configuration structure: update the configuration file (see UPGRADE.md) and run "app:config:sync".', 0, $e);
            }
        }

        return $this->definition;
    }

    public function reset(): void
    {
        $this->definition = null;
    }
}
