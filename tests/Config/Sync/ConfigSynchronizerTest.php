<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\ConfigException;
use App\Config\ConfigRegistry;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\MigrationPolicy;
use App\Config\Sync\ConfigSynchronizer;
use App\Config\Sync\PlaceMigration;
use App\Entity\Enum\PlaceStatus;
use App\Entity\IssueLink;
use App\Entity\Tracker;
use App\Entity\User;
use App\Factory\TrackerFactory;
use App\Factory\UserFactory;
use App\Repository\ConfigSnapshotRepository;
use App\Repository\IssueRepository;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\MessageHandler\Query\Issue\IssueBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Issues are created on a tracker bound to the test `bug` workflow, whose
 * `to_test` place is the one removed by `bugWorkflow(withToTest: false)`.
 */
final class ConfigSynchronizerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ConfigSynchronizer $synchronizer;
    private ConfigSnapshotRepository $snapshots;
    private ConfigRegistry $registry;
    private IssueRepository $issues;
    private IssueBuilder $builder;
    private Tracker $tracker;
    private User $author;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->synchronizer = $container->get(ConfigSynchronizer::class);
        $this->snapshots = $container->get(ConfigSnapshotRepository::class);
        $this->registry = $container->get(ConfigRegistry::class);
        $this->registry->reset();
        $this->issues = $container->get(IssueRepository::class);
        $this->builder = new IssueBuilder($container->get(EntityManagerInterface::class));
        $this->tracker = TrackerFactory::createOne(['workflowId' => 'bug']);
        $this->author = UserFactory::new()->internal()->create();
    }

    public function testFirstSyncCreatesTheSnapshotAndFeedsTheRegistry(): void
    {
        self::assertNull($this->snapshots->findLatest());
        try {
            $this->registry->definition();
            self::fail('No snapshot yet');
        } catch (ConfigException) {
        }

        self::mockTime('2026-03-03 10:00:00');
        $plan = $this->synchronizer->plan(DefinitionFactory::definition());

        self::assertTrue($plan->isValid());
        self::assertNull($plan->currentHash);
        self::assertTrue($plan->hasChanges());
        self::assertSame([], $plan->migrations);

        $snapshot = $this->synchronizer->apply($plan, 'tests');

        self::assertNotNull($snapshot->getId());
        self::assertSame($plan->hash, $snapshot->getHash());
        self::assertSame('tests', $snapshot->getAppliedBy());
        self::assertSame('2026-03-03 10:00:00', $snapshot->getAppliedAt()->format('Y-m-d H:i:s'), 'dated by the mocked clock');
        self::assertStringContainsString('+ workflows.bug', $snapshot->getSummary());
        self::assertSame($snapshot->getId(), $this->snapshots->findLatest()?->getId());
        self::assertEquals(DefinitionFactory::definition(), $this->registry->definition());
    }

    public function testAnUnchangedDefinitionHasNothingToApply(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));

        $plan = $this->synchronizer->plan(DefinitionFactory::definition());

        self::assertFalse($plan->hasChanges());
        self::assertSame([], $plan->diff);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Nothing to apply');
        $this->synchronizer->apply($plan);
    }

    public function testInvalidDefinitionCannotBeApplied(): void
    {
        $workflow = new WorkflowDefinition(id: 'w', label: 'W', initial: 'nowhere', places: [new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open)], transitions: []);
        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [$workflow]));

        self::assertFalse($plan->isValid());

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('violations');
        $this->synchronizer->apply($plan);
    }

    public function testRemovingAPlaceWithIssuesIsBlockedUnderTheStrictPolicy(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $this->issuesOn('to_test', 3);

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false)]));

        self::assertTrue($plan->isValid());
        self::assertTrue($plan->isBlocked());
        self::assertCount(1, $plan->orphans);
        self::assertSame(['bug', 'to_test', 3], [$plan->orphans[0]->workflowId, $plan->orphans[0]->placeId, $plan->orphans[0]->count]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('no migration target');
        $this->synchronizer->apply($plan);
    }

    public function testRemovingAnEmptyPlaceIsNotBlocking(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $this->issuesOn('new', 2);

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false)]));

        self::assertCount(1, $plan->orphans);
        self::assertSame(0, $plan->orphans[0]->count);
        self::assertFalse($plan->isBlocked());
        self::assertNotNull($this->synchronizer->apply($plan)->getId());
        self::assertSame(2, $this->issues->countOnPlace('bug', 'new'), 'untouched');
    }

    public function testRemovingALinkTypeStillInUseIsBlocked(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new IssueLink($this->builder->issue($this->tracker, $this->author, 'Bloquante'), 'blocks', $this->builder->issue($this->tracker, $this->author, 'Bloquée'), $this->author));
        $entityManager->flush();

        $withoutBlocks = $this->synchronizer->plan(DefinitionFactory::definition(links: [new LinkTypeDefinition(id: 'related', label: 'Lié à')]));
        self::assertTrue($withoutBlocks->isBlocked());
        self::assertSame(['links.blocks: 1 links still use it'], $withoutBlocks->usedLinkTypes);

        $withoutRelated = $this->synchronizer->plan(DefinitionFactory::definition(links: [new LinkTypeDefinition(id: 'blocks', label: 'Bloque', inverseLabel: 'Bloqué par')]));
        self::assertFalse($withoutRelated->isBlocked(), 'an unused link type goes');

        $this->expectException(ConfigException::class);
        $this->synchronizer->apply($withoutBlocks);
    }

    public function testMigrateFromMovesTheIssuesOfARemovedPlace(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $this->issuesOn('to_test', 3);
        $this->issuesOn('in_progress', 1);

        $next = DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false, migrateInProgressFrom: ['to_test'])]);
        $plan = $this->synchronizer->plan($next);

        self::assertSame([], $plan->orphans);
        self::assertCount(1, $plan->migrations);
        self::assertSame(PlaceMigration::BY_RULE, $plan->migrations[0]->reason);
        self::assertSame(['bug', 'to_test', 'in_progress', 3], [$plan->migrations[0]->workflowId, $plan->migrations[0]->from, $plan->migrations[0]->to, $plan->migrations[0]->count]);

        $snapshot = $this->synchronizer->apply($plan);

        self::assertSame(0, $this->issues->countOnPlace('bug', 'to_test'));
        self::assertSame(4, $this->issues->countOnPlace('bug', 'in_progress'));
        self::assertStringContainsString('3 issue(s) from "to_test" to "in_progress"', $snapshot->getSummary());
        self::assertEquals($next, $this->registry->definition());
    }

    public function testFallbackPolicyMovesOrphansToTheFallbackPlace(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $this->issuesOn('to_test', 2);

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(
            workflows: [DefinitionFactory::bugWorkflow(withToTest: false)],
            migrationPolicy: MigrationPolicy::Fallback,
        ));

        self::assertSame([], $plan->orphans);
        self::assertSame(PlaceMigration::BY_FALLBACK, $plan->migrations[0]->reason);
        self::assertSame('new', $plan->migrations[0]->to);
        self::assertFalse($plan->isBlocked());

        $this->synchronizer->apply($plan);

        self::assertSame(2, $this->issues->countOnPlace('bug', 'new'));
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

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('changed since the plan was computed');
        $this->synchronizer->apply($stale);
    }

    private function issuesOn(string $place, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->builder->issue($this->tracker, $this->author, "$place $i", $place);
        }
    }
}
