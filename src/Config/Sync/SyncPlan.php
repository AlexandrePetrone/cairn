<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Config\ConfigViolation;
use App\Config\Definition\InstanceDefinition;

/**
 * Everything the sync would do, computed without side effects so that it
 * can be shown (dry run) before being applied.
 */
final readonly class SyncPlan
{
    /**
     * @param array<string, mixed>  $encoded       canonical form of the definition
     * @param string|null           $currentHash   hash of the snapshot in force when the plan was computed
     * @param list<ConfigViolation> $violations
     * @param list<string>          $diff          one line per change against the snapshot in force, or one line saying it cannot be read
     * @param list<PlaceMigration>  $migrations
     * @param list<OrphanPlace>     $orphans
     * @param list<string>          $warnings
     * @param list<string>          $usedLinkTypes removed link types that links still use
     * @param list<string>          $usedWorkflows removed workflows that trackers, of projects or of project templates, still use
     */
    public function __construct(
        public InstanceDefinition $definition,
        public array $encoded,
        public string $hash,
        public ?string $currentHash,
        public array $violations,
        public array $diff,
        public array $migrations,
        public array $orphans,
        public array $warnings,
        public array $usedLinkTypes = [],
        public array $usedWorkflows = [],
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
     * An orphan place cannot be applied under the strict policy, nor a
     * removed link type or workflow still in use.
     */
    public function isBlocked(): bool
    {
        return [] !== $this->usedLinkTypes || [] !== $this->usedWorkflows || [] !== $this->orphans;
    }

    public function summary(): string
    {
        return implode("\n", [
            ...$this->diff,
            ...array_map(static fn (PlaceMigration $m) => "> $m", $this->migrations),
        ]);
    }
}
