<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Config\ConfigException;
use App\Config\ConfigRegistry;
use App\Config\ConfigValidator;
use App\Config\Definition\InstanceDefinition;
use App\Config\DiffCalculator;
use App\Config\MigrationPolicy;
use App\Config\SnapshotCodec;
use App\Entity\ConfigSnapshot;
use App\Entity\Enum\PlaceStatus;
use App\Repository\ConfigSnapshotRepository;
use App\Repository\IssueLinkRepository;
use App\Repository\IssueRepository;
use App\Repository\TemplateTrackerRepository;
use App\Repository\TrackerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Turns a definition into a snapshot: validate, diff against the snapshot
 * in force, plan the data migrations, then apply everything atomically.
 * A plain service so that a future back-office can reuse it.
 *
 * The plan reads the data, never the snapshot in force: the places, link
 * types and workflows in use come from the issues, the links and the
 * trackers themselves. The snapshot in force only feeds the list of
 * changes shown, and when this version cannot read it (its structure
 * changed), the list says so and the sync still applies, which is how an
 * instance gets out of that state.
 */
final class ConfigSynchronizer
{
    private const string LOCK_NAME = 'cairn.config-sync';

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly SnapshotCodec $codec,
        private readonly DiffCalculator $diffCalculator,
        private readonly ConfigSnapshotRepository $snapshots,
        private readonly IssueRepository $issues,
        private readonly IssueLinkRepository $links,
        private readonly TrackerRepository $trackers,
        private readonly TemplateTrackerRepository $templateTrackers,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly ConfigRegistry $registry,
        private readonly ClockInterface $clock,
    ) {
    }

    public function plan(InstanceDefinition $next): SyncPlan
    {
        $violations = $this->validator->validate($next);
        $encoded = $this->codec->encode($next);
        $hash = $this->codec->hash($encoded);

        $currentSnapshot = $this->snapshots->findLatest();
        try {
            $current = null === $currentSnapshot ? null : $this->codec->decode($currentSnapshot->getDefinition());
            $diff = $this->diffCalculator->between($current, $next);
        } catch (ConfigException $e) {
            $diff = [\sprintf('~ the snapshot in force cannot be read by this version, the changes are not listed (%s)', $e->getMessage())];
        }

        $migrations = [];
        $orphans = [];
        $removedPlaces = [];

        foreach ($this->issues->countByPlace() as ['workflowId' => $workflowId, 'placeId' => $placeId, 'issues' => $count]) {
            if (!$next->hasWorkflow($workflowId)) {
                $orphans[] = new OrphanPlace($workflowId, $placeId, $count, 'the workflow is not in the configuration');
                continue;
            }

            $workflow = $next->workflow($workflowId);
            if ($workflow->hasPlace($placeId)) {
                continue;
            }

            $removedPlaces[$workflowId][] = $placeId;
            $target = $workflow->migrationTargetFor($placeId);

            if (null !== $target) {
                $migrations[] = new PlaceMigration($workflowId, $placeId, $target, $count, PlaceMigration::BY_RULE);
            } elseif (MigrationPolicy::Fallback === $next->migrationPolicy && null !== $workflow->fallbackPlace) {
                $migrations[] = new PlaceMigration($workflowId, $placeId, $workflow->fallbackPlace, $count, PlaceMigration::BY_FALLBACK);
            } else {
                $orphans[] = new OrphanPlace($workflowId, $placeId, $count, 'no migrateFrom rule targets it');
            }
        }

        // Links are never migrated: a type still in use stays in the file.
        $usedLinkTypes = [];
        foreach ($this->links->countByType() as $typeId => $count) {
            if (!$next->hasLink($typeId)) {
                $usedLinkTypes[] = \sprintf('links.%s: %d links still use it', $typeId, $count);
            }
        }

        // Nor are trackers: a workflow they are bound to stays in the file.
        $usedWorkflows = [];
        foreach ($this->trackers->countByWorkflow() as $workflowId => $count) {
            if (!$next->hasWorkflow($workflowId)) {
                $usedWorkflows[] = \sprintf('workflows.%s: %d tracker(s) still use it', $workflowId, $count);
            }
        }
        // Nor the trackers of the project templates, which the next project would be given.
        foreach ($this->templateTrackers->templatesByWorkflow() as $workflowId => $templates) {
            if (!$next->hasWorkflow($workflowId)) {
                $usedWorkflows[] = \sprintf('workflows.%s: the project template(s) "%s" still use it', $workflowId, implode('", "', $templates));
            }
        }

        $warnings = [];
        foreach ($next->workflows as $workflow) {
            foreach ($workflow->places as $place) {
                foreach ($place->migrateFrom as $oldPlaceId) {
                    if (!\in_array($oldPlaceId, $removedPlaces[$workflow->id] ?? [], true)) {
                        $warnings[] = \sprintf('workflows.%s.places.%s.migrateFrom: no issue sits on "%s", the rule can be removed.', $workflow->id, $place->id, $oldPlaceId);
                    }
                }
            }
        }

        return new SyncPlan($next, $encoded, $hash, $currentSnapshot?->getHash(), $violations, $diff, $migrations, $orphans, $warnings, $usedLinkTypes, $usedWorkflows);
    }

    /**
     * Moves the issues of removed places in bulk (entering a closed place
     * closes them, leaving one reopens them) and stores the new snapshot.
     *
     * @param string|null $appliedBy who applies it, when known (a future back-office)
     *
     * @throws ConfigException when the plan cannot be applied or is stale
     */
    public function apply(SyncPlan $plan, ?string $appliedBy = null): ConfigSnapshot
    {
        if (!$plan->isValid()) {
            throw new ConfigException('The definition has violations; fix them before applying.');
        }
        if (!$plan->hasChanges()) {
            throw new ConfigException('Nothing to apply: the definition matches the snapshot in force.');
        }
        if ($plan->isBlocked()) {
            throw new ConfigException('Some removed places still hold issues and have no migration target, or some removed link types or workflows are still used.');
        }

        $lock = $this->lockFactory->createLock(self::LOCK_NAME, ttl: 300, autoRelease: true);
        if (!$lock->acquire()) {
            throw new ConfigException('Another configuration sync is in progress.');
        }

        try {
            $snapshot = $this->entityManager->wrapInTransaction(function () use ($plan, $appliedBy): ConfigSnapshot {
                if ($this->snapshots->findLatest()?->getHash() !== $plan->currentHash) {
                    throw new ConfigException('The snapshot in force changed since the plan was computed; compute it again.');
                }

                foreach ($plan->migrations as $migration) {
                    $target = $plan->definition->workflow($migration->workflowId)->place($migration->to);
                    $closedAt = PlaceStatus::Closed === $target->status ? $this->clock->now() : null;
                    $this->issues->moveFromPlace($migration->workflowId, $migration->from, $target->id, $target->status, $closedAt);
                }

                $snapshot = new ConfigSnapshot($plan->hash, $plan->encoded, $plan->summary(), $appliedBy, $this->clock->now());
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
