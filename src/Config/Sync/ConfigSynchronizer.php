<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Config\ConfigValidator;
use App\Config\Definition\InstanceDefinition;
use App\Config\Diff\DiffCalculator;
use App\Config\MigrationPolicy;
use App\Config\Snapshot\ConfigRegistry;
use App\Config\Snapshot\SnapshotCodec;
use App\Entity\ConfigSnapshot;
use App\Repository\ConfigSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Turns a definition into a snapshot: validate, diff against the snapshot
 * in force, plan the data migrations, then apply everything atomically.
 * A plain service so that a future back-office can reuse it.
 */
final class ConfigSynchronizer
{
    private const string LOCK_NAME = 'cairn.config-sync';

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly SnapshotCodec $codec,
        private readonly DiffCalculator $diffCalculator,
        private readonly ConfigSnapshotRepository $snapshots,
        private readonly EntityManagerInterface $entityManager,
        private readonly IssuePlaceGateway $issues,
        private readonly LockFactory $lockFactory,
        private readonly ConfigRegistry $registry,
    ) {
    }

    public function plan(InstanceDefinition $next): SyncPlan
    {
        $violations = $this->validator->validate($next);
        $encoded = $this->codec->encode($next);
        $hash = $this->codec->hash($encoded);

        $currentSnapshot = $this->snapshots->findLatest();
        $current = null === $currentSnapshot ? null : $this->codec->decode($currentSnapshot->getDefinition());
        $diff = $this->diffCalculator->between($current, $next);

        $migrations = [];
        $orphans = [];
        $removedPlaces = [];

        foreach ($current->workflows ?? [] as $workflowId => $previous) {
            if (!$next->hasWorkflow($workflowId)) {
                foreach ($previous->placeIds() as $placeId) {
                    $count = $this->issues->countByPlace($workflowId, $placeId);
                    if ($count > 0) {
                        $orphans[] = new OrphanPlace($workflowId, $placeId, $count, 'the workflow is removed');
                    }
                }
                continue;
            }

            $workflow = $next->workflow($workflowId);
            foreach (array_diff($previous->placeIds(), $workflow->placeIds()) as $placeId) {
                $removedPlaces[$workflowId][] = $placeId;
                $count = $this->issues->countByPlace($workflowId, $placeId);
                $target = $workflow->migrationTargetFor($placeId);

                if (null !== $target) {
                    $migrations[] = new PlaceMigration($workflowId, $placeId, $target, $count, MigrationReason::MigrateFrom);
                } elseif (MigrationPolicy::Fallback === $next->migrationPolicy && null !== $workflow->fallbackPlace) {
                    $migrations[] = new PlaceMigration($workflowId, $placeId, $workflow->fallbackPlace, $count, MigrationReason::Fallback);
                } else {
                    $orphans[] = new OrphanPlace($workflowId, $placeId, $count, 'no migrateFrom rule targets it');
                }
            }
        }

        $warnings = [];
        foreach ($next->workflows as $workflow) {
            foreach ($workflow->places as $place) {
                foreach ($place->migrateFrom as $oldPlaceId) {
                    if (!\in_array($oldPlaceId, $removedPlaces[$workflow->id] ?? [], true)) {
                        $warnings[] = \sprintf('workflows.%s.places.%s.migrateFrom: "%s" matches nothing, the rule can be removed.', $workflow->id, $place->id, $oldPlaceId);
                    }
                }
            }
        }

        return new SyncPlan($next, $encoded, $hash, $currentSnapshot?->getHash(), $violations, $diff, $migrations, $orphans, $warnings);
    }

    /**
     * @param string|null $appliedBy who applies it, when known (a future back-office)
     *
     * @throws SyncException when the plan cannot be applied or is stale
     */
    public function apply(SyncPlan $plan, ?string $appliedBy = null): ConfigSnapshot
    {
        if (!$plan->isValid()) {
            throw new SyncException('The definition has violations; fix them before applying.');
        }
        if (!$plan->hasChanges()) {
            throw new SyncException('Nothing to apply: the definition matches the snapshot in force.');
        }
        if ($plan->isBlocked()) {
            throw new SyncException('Some removed places still hold issues and have no migration target.');
        }

        $lock = $this->lockFactory->createLock(self::LOCK_NAME, ttl: 300, autoRelease: true);
        if (!$lock->acquire()) {
            throw new SyncException('Another configuration sync is in progress.');
        }

        try {
            $snapshot = $this->entityManager->wrapInTransaction(function () use ($plan, $appliedBy): ConfigSnapshot {
                if ($this->snapshots->findLatest()?->getHash() !== $plan->currentHash) {
                    throw new SyncException('The snapshot in force changed since the plan was computed; compute it again.');
                }

                foreach ($plan->migrations as $migration) {
                    if ($migration->count > 0) {
                        $this->issues->movePlace($migration->workflowId, $migration->from, $migration->to);
                    }
                }

                $snapshot = new ConfigSnapshot($plan->hash, $plan->encoded, $plan->summary(), $appliedBy);
                $this->entityManager->persist($snapshot);

                return $snapshot;
            });
        } finally {
            $lock->release();
        }

        $this->registry->reset();

        return $snapshot;
    }
}
