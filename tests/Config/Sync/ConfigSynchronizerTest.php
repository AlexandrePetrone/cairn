<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\ConfigException;
use App\Config\ConfigRegistry;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\MigrationPolicy;
use App\Config\Sync\ConfigSynchronizer;
use App\Config\Sync\PlaceMigration;
use App\Entity\ConfigSnapshot;
use App\Entity\Enum\PlaceStatus;
use App\Entity\IssueLink;
use App\Entity\ProjectTemplate;
use App\Entity\TemplateTracker;
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

        self::assertSame([], $plan->orphans, 'a place nobody sits on is not even mentioned');
        self::assertSame([], $plan->migrations);
        self::assertFalse($plan->isBlocked());
        self::assertNotNull($this->synchronizer->apply($plan)->getId());
        self::assertSame(2, $this->countOn('bug', 'new'), 'untouched');
    }

    public function testRemovingALinkTypeStillInUseIsBlocked(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new IssueLink($this->builder->issue($this->tracker, $this->author, 'Blocking'), 'blocks', $this->builder->issue($this->tracker, $this->author, 'Blocked'), $this->author));
        $entityManager->flush();

        $withoutBlocks = $this->synchronizer->plan(DefinitionFactory::definition(links: [new LinkTypeDefinition(id: 'related', label: 'Related to')]));
        self::assertTrue($withoutBlocks->isBlocked());
        self::assertSame(['links.blocks: 1 links still use it'], $withoutBlocks->usedLinkTypes);

        $withoutRelated = $this->synchronizer->plan(DefinitionFactory::definition(links: [new LinkTypeDefinition(id: 'blocks', label: 'Blocks', inverseLabel: 'Blocked by')]));
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

        self::assertSame(0, $this->countOn('bug', 'to_test'));
        self::assertSame(4, $this->countOn('bug', 'in_progress'));
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

        self::assertSame(2, $this->countOn('bug', 'new'));
    }

    public function testAMigrateFromRuleMatchingNothingIsAWarning(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(migrateInProgressFrom: ['ancient'])]));

        self::assertSame(['workflows.bug.places.in_progress.migrateFrom: no issue sits on "ancient", the rule can be removed.'], $plan->warnings);
    }

    public function testIssuesOnAPlaceOfAnUnknownWorkflowAreOrphans(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $this->issuesOn('new', 1);
        $this->issuesOn('done', 1);

        $bug = DefinitionFactory::bugWorkflow();
        $defect = new WorkflowDefinition(id: 'defect', label: 'Defect', initial: $bug->initial, places: array_values($bug->places), transitions: array_values($bug->transitions), fields: array_values($bug->fields), fallbackPlace: $bug->fallbackPlace);
        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [$defect]));

        self::assertTrue($plan->isBlocked());
        self::assertSame(['bug.done', 'bug.new'], array_map(static fn ($o) => "$o->workflowId.$o->placeId", $plan->orphans));
        self::assertSame('the workflow is not in the configuration', $plan->orphans[0]->reason);
        self::assertSame(['workflows.bug: 1 tracker(s) still use it'], $plan->usedWorkflows);
    }

    public function testRemovingAWorkflowWithTrackersButNoIssueIsBlocked(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $bug = DefinitionFactory::bugWorkflow();
        $defect = new WorkflowDefinition(id: 'defect', label: 'Defect', initial: $bug->initial, places: array_values($bug->places), transitions: array_values($bug->transitions), fields: array_values($bug->fields), fallbackPlace: $bug->fallbackPlace);

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [$defect]));

        self::assertSame([], $plan->orphans);
        self::assertSame(['workflows.bug: 1 tracker(s) still use it'], $plan->usedWorkflows);
        self::assertTrue($plan->isBlocked());

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('workflows are still used');
        $this->synchronizer->apply($plan);
    }

    /**
     * A snapshot written by a version whose definition objects differ
     * (here a role with a key that no longer exists) cannot be decoded
     * by this one. The sync must still plan and apply from the data alone,
     * since it is the only way out of that state.
     */
    public function testASnapshotThisVersionCannotReadDoesNotBlockTheSync(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $this->issuesOn('to_test', 2);
        $this->storeUnreadableSnapshot();

        $this->registry->reset();
        try {
            $this->registry->definition();
            self::fail('The runtime cannot use the snapshot');
        } catch (ConfigException $e) {
            self::assertStringContainsString('Unreadable snapshot (format 1): Unknown named parameter $colour', $e->getMessage());
            self::assertStringContainsString('run "app:config:sync"', $e->getMessage());
        }

        $blocked = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false)]));
        self::assertTrue($blocked->isBlocked(), 'the orphans are found in the data, not in the old snapshot');
        self::assertCount(1, $blocked->diff);
        self::assertStringContainsString('cannot be read by this version', $blocked->diff[0]);
        self::assertSame([], $blocked->warnings);

        $next = DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false, migrateInProgressFrom: ['to_test'])]);
        $plan = $this->synchronizer->plan($next);
        self::assertFalse($plan->isBlocked());
        self::assertTrue($plan->hasChanges());
        self::assertSame(2, $plan->migrations[0]->count);

        $snapshot = $this->synchronizer->apply($plan);

        self::assertSame(2, $this->countOn('bug', 'in_progress'));
        self::assertEquals($next, $this->registry->definition());
        self::assertStringContainsString('cannot be read by this version', $snapshot->getSummary(), 'the history says why nothing is listed');
    }

    /**
     * A snapshot written before `followsNewIssues` existed, by a version
     * where no role followed new issues, reads as if the key were false.
     */
    public function testAnUnchangedFileRewritesASnapshotOfAnotherShape(): void
    {
        $definition = DefinitionFactory::definition(roles: [
            new RoleDefinition(id: 'project_manager', label: 'Project manager', internalOnly: true),
            new RoleDefinition(id: 'developer', label: 'Developer', internalOnly: true),
            new RoleDefinition(id: 'client', label: 'Client'),
        ]);
        $this->synchronizer->apply($this->synchronizer->plan($definition));
        $this->storeSnapshotWithoutKey('followsNewIssues');

        $plan = $this->synchronizer->plan($definition);

        self::assertTrue($plan->hasChanges(), 'the stored form differs');
        self::assertSame([], $plan->diff, 'the definition does not');
        self::assertSame([], $plan->warnings);
        self::assertNotNull($this->synchronizer->apply($plan)->getId());
    }

    public function testAStalePlanIsRefused(): void
    {
        $stale = $this->synchronizer->plan(DefinitionFactory::definition());
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(label: 'Defect')])));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('changed since the plan was computed');
        $this->synchronizer->apply($stale);
    }

    /**
     * Stores, as the snapshot in force, one written by a version whose roles
     * had a `colour`.
     */
    private function storeUnreadableSnapshot(): void
    {
        $definition = $this->snapshots->findLatest()?->getDefinition() ?? self::fail('a snapshot is in force');
        $definition['format'] = 1;
        $definition['roles'][0]['colour'] = 'amber';
        $this->storeSnapshot($definition);
    }

    /**
     * Stores, as the snapshot in force, one written by a version whose roles
     * had no `$key`.
     */
    private function storeSnapshotWithoutKey(string $key): void
    {
        $definition = $this->snapshots->findLatest()?->getDefinition() ?? self::fail('a snapshot is in force');
        $definition['format'] = 1;
        foreach ($definition['roles'] as &$role) {
            unset($role[$key]);
        }
        $this->storeSnapshot($definition);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function storeSnapshot(array $definition): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ConfigSnapshot(hash('sha256', 'older'), $definition, '', 'tests', self::mockTime()->now()));
        $entityManager->flush();
        $this->registry->reset();
    }

    private function countOn(string $workflow, string $place): int
    {
        foreach ($this->issues->countByPlace() as $row) {
            if ($row['workflowId'] === $workflow && $row['placeId'] === $place) {
                return $row['issues'];
            }
        }

        return 0;
    }

    public function testRemovingAWorkflowAProjectTemplateUsesIsBlocked(): void
    {
        $this->synchronizer->apply($this->synchronizer->plan(DefinitionFactory::definition()));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($template = new ProjectTemplate('Web project'));
        $entityManager->persist(new TemplateTracker($template, 'Bug', 'bug'));
        $entityManager->flush();
        $bug = DefinitionFactory::bugWorkflow();
        $defect = new WorkflowDefinition(id: 'defect', label: 'Defect', initial: $bug->initial, places: array_values($bug->places), transitions: array_values($bug->transitions), fields: array_values($bug->fields), fallbackPlace: $bug->fallbackPlace);

        $plan = $this->synchronizer->plan(DefinitionFactory::definition(workflows: [$defect]));

        self::assertContains('workflows.bug: the project template(s) "Web project" still use it', $plan->usedWorkflows);
        self::assertTrue($plan->isBlocked());
    }

    private function issuesOn(string $place, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->builder->issue($this->tracker, $this->author, "$place $i", $place);
        }
    }
}
