<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\ConfigValidator;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\Diff\DiffCalculator;
use App\Config\MigrationPolicy;
use App\Config\Snapshot\ConfigRegistry;
use App\Config\Snapshot\NoSnapshotException;
use App\Config\Snapshot\SnapshotCodec;
use App\Config\Sync\ConfigSynchronizer;
use App\Config\Sync\MigrationReason;
use App\Config\Sync\SyncException;
use App\Entity\Enum\PlaceStatus;
use App\Repository\ConfigSnapshotRepository;
use App\Tests\Config\Fixture\DefinitionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

final class ConfigSynchronizerTest extends KernelTestCase
{
    private ConfigSynchronizer $synchronizer;
    private ConfigSnapshotRepository $snapshots;
    private ConfigRegistry $registry;
    private InMemoryIssuePlaceGateway $issues;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->issues = new InMemoryIssuePlaceGateway();
        $this->snapshots = $container->get(ConfigSnapshotRepository::class);
        $this->registry = $container->get(ConfigRegistry::class);
        $this->registry->reset();

        $this->synchronizer = new ConfigSynchronizer(
            new ConfigValidator(),
            $container->get(SnapshotCodec::class),
            $container->get(DiffCalculator::class),
            $this->snapshots,
            $container->get(EntityManagerInterface::class),
            $this->issues,
            $container->get(LockFactory::class),
            $this->registry,
        );
    }

    public function testFirstSyncCreatesTheSnapshotAndFeedsTheRegistry(): void
    {
        self::assertNull($this->snapshots->findLatest());
        try {
            $this->registry->definition();
            self::fail('No snapshot yet');
        } catch (NoSnapshotException) {
        }

        $plan = $this->synchronizer->plan(DefinitionFactory::definition());

        self::assertTrue($plan->isValid());
        self::assertNull($plan->currentHash);
        self::assertTrue($plan->hasChanges());
        self::assertSame([], $plan->migrations);

        $snapshot = $this->synchronizer->apply($plan, 'tests');

        self::assertNotNull($snapshot->getId());
        self::assertSame($plan->hash, $snapshot->getHash());
        self::assertSame('tests', $snapshot->getAppliedBy());
        self::assertStringContainsString('+ workflows.bug', $snapshot->getSummary());
        self::assertSame($snapshot->getId(), $this->snapshots->findLatest()?->getId());
        self::assertEquals(DefinitionFactory::definition(), $this->registry->definition());
    }

    public function testAnUnchangedDefinitionHasNothingToApply(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));

        $plan = $this->synchronizer->plan(DefinitionFactory::definition());

        self::assertFalse($plan->hasChanges());
        self::assertTrue($plan->diff->isEmpty());

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('Nothing to apply');
        $this->synchronizer->apply($plan);
    }

    public function testInvalidDefinitionCannotBeApplied(): void
    {
        $workflow = new WorkflowDefinition(id: 'w', label: 'W', initial: 'nowhere', places: [new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open)], transitions: []);
        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [$workflow]));

        self::assertFalse($plan->isValid());

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('violations');
        $this->synchronizer->apply($plan);
    }

    public function testRemovingAPlaceWithIssuesIsBlockedUnderTheStrictPolicy(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $this->issues->set('bug', 'to_test', 3);

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false)]));

        self::assertTrue($plan->isValid());
        self::assertTrue($plan->isBlocked());
        self::assertCount(1, $plan->orphans);
        self::assertSame(['bug', 'to_test', 3], [$plan->orphans[0]->workflowId, $plan->orphans[0]->placeId, $plan->orphans[0]->count]);

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('no migration target');
        $this->synchronizer->apply($plan);
    }

    public function testRemovingAnEmptyPlaceIsNotBlocking(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false)]));

        self::assertCount(1, $plan->orphans);
        self::assertSame(0, $plan->orphans[0]->count);
        self::assertFalse($plan->isBlocked());
        self::assertNotNull($this->synchronizer->apply($plan)->getId());
    }

    public function testMigrateFromMovesTheIssuesOfARemovedPlace(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $this->issues->set('bug', 'to_test', 3);

        $next = DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false, migrateInProgressFrom: ['to_test'])]);
        $plan = $this->synchronizer->plan($next);

        self::assertSame([], $plan->orphans);
        self::assertCount(1, $plan->migrations);
        self::assertSame(MigrationReason::MigrateFrom, $plan->migrations[0]->reason);
        self::assertSame(['bug', 'to_test', 'in_progress', 3], [$plan->migrations[0]->workflowId, $plan->migrations[0]->from, $plan->migrations[0]->to, $plan->migrations[0]->count]);

        $snapshot = $this->synchronizer->apply($plan);

        self::assertSame([['bug', 'to_test', 'in_progress', 3]], $this->issues->moves);
        self::assertSame(0, $this->issues->countByPlace('bug', 'to_test'));
        self::assertStringContainsString('3 issue(s) from "to_test" to "in_progress"', $snapshot->getSummary());
        self::assertEquals($next, $this->registry->definition());
    }

    public function testFallbackPolicyMovesOrphansToTheFallbackPlace(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $this->issues->set('bug', 'to_test', 2);

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(
            workflows: [DefinitionFactory::bugWorkflow(withToTest: false)],
            migrationPolicy: MigrationPolicy::Fallback,
        ));

        self::assertSame([], $plan->orphans);
        self::assertSame(MigrationReason::Fallback, $plan->migrations[0]->reason);
        self::assertSame('new', $plan->migrations[0]->to);
        self::assertFalse($plan->isBlocked());
    }

    public function testAMigrateFromRuleMatchingNothingIsAWarning(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(migrateInProgressFrom: ['ancient'])]));

        self::assertSame(['workflows.bug.places.in_progress.migrateFrom: "ancient" matches nothing, the rule can be removed.'], $plan->warnings);
    }

    public function testAStalePlanIsRefused(): void
    {
        $stale = $this->synchronizer->plan(DefinitionFactory::definition());
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(label: 'Bug')])));

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('changed since the plan was computed');
        $this->synchronizer->apply($stale);
    }
}
