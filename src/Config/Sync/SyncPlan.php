<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Config\ConfigViolation;
use App\Config\Definition\InstanceDefinition;
use App\Config\Diff\ConfigDiff;

/**
 * Everything the sync would do, computed without side effects so that it
 * can be shown (dry run) before being applied.
 */
final readonly class SyncPlan
{
    /**
     * @param array<string, mixed>  $encoded     canonical form of the definition
     * @param string|null           $currentHash hash of the snapshot in force when the plan was computed
     * @param list<ConfigViolation> $violations
     * @param list<PlaceMigration>  $migrations
     * @param list<OrphanPlace>     $orphans
     * @param list<string>          $warnings
     */
    public function __construct(
        public InstanceDefinition $definition,
        public array $encoded,
        public string $hash,
        public ?string $currentHash,
        public array $violations,
        public ConfigDiff $diff,
        public array $migrations,
        public array $orphans,
        public array $warnings,
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->violations;
    }

    public function hasChanges(): bool
    {
        return $this->currentHash !== $this->hash;
    }

    /**
     * An orphan place still holding issues cannot be applied under the
     * strict policy.
     */
    public function isBlocked(): bool
    {
        foreach ($this->orphans as $orphan) {
            if ($orphan->count > 0) {
                return true;
            }
        }

        return false;
    }

    public function summary(): string
    {
        return implode("\n", [
            ...$this->diff->describe(),
            ...array_map(static fn (PlaceMigration $m) => "> $m", $this->migrations),
        ]);
    }
}
