<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Tracker;

use App\Entity\AutomationRule;
use App\Entity\Tracker;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ChangeTracker;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Tracker\CreateTracker;
use App\Message\Command\Tracker\DeleteTracker;
use App\Message\Command\Tracker\UpdateTracker;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\MessageHandler\Command\Tracker\TrackerException;
use App\Repository\AutomationRuleRepository;
use App\Repository\TrackerRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * CreateTracker and UpdateTracker. The shop holds Bug (0) and the
 * private Internal task (1); project managers configure it.
 */
final class TrackerHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private TrackerRepository $trackers;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $this->trackers = self::getContainer()->get(TrackerRepository::class);
    }

    public function testAManagerCreatesATrackerAtTheEndOfTheList(): void
    {
        $tracker = $this->create('Support', private: true);

        self::assertSame(2, $tracker->getPosition());
        self::assertSame('bug', $tracker->getWorkflowId());
        self::assertSame('For the questions.', $tracker->getDescription());
        self::assertTrue($tracker->isPrivate());
        self::assertFalse($tracker->isArchived());
    }

    public function testATrackerIsBoundToAWorkflowOfTheConfiguration(): void
    {
        $this->expectException(TrackerException::class);
        $this->create('Support', workflowId: 'unknown');
    }

    public function testOnlyWhoConfiguresTheProjectCreatesOrChangesATracker(): void
    {
        try {
            $this->commands->dispatch(new CreateTracker((int) $this->fixtures->developer->getId(), (int) $this->fixtures->access->shop->getId(), 'Support', 'bug'));
            self::fail('A developer does not configure the project.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateTracker((int) $this->fixtures->client->getId(), (int) $this->fixtures->bugs->getId(), name: 'Defect'));
    }

    public function testATrackerIsRenamedAndArchivedThenTakesNoNewIssue(): void
    {
        $task = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks);
        $this->update(name: 'Defect', description: '', private: true, archived: true);

        $bugs = $this->fixtures->bugs;
        self::assertSame('Defect', $bugs->getName());
        self::assertNull($bugs->getDescription());
        self::assertTrue($bugs->isPrivate());
        self::assertTrue($bugs->isArchived());
        self::assertNotContains($bugs, $this->trackers->findByProject($this->fixtures->access->shop));

        try {
            $this->commands->dispatch(new ChangeTracker((int) $this->fixtures->manager->getId(), $task->getKey(), (int) $bugs->getId(), 'new'));
            self::fail('An archived tracker takes no issue from another one.');
        } catch (IssueException $e) {
            self::assertStringContainsString('archived', $e->getMessage());
        }

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('archived');
        $this->fixtures->createIssue($this->fixtures->manager);
    }

    public function testTheIssuesOfAnArchivedTrackerAreStillChanged(): void
    {
        $bug = $this->fixtures->createIssue($this->fixtures->client);
        $this->update(archived: true);

        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $bug->getKey(), 'Still there.'));
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $bug->getKey(), title: 'Renamed'));

        self::assertSame('Renamed', $bug->getTitle());

        $this->update(archived: false);
        $this->fixtures->createIssue($this->fixtures->client);
    }

    public function testAnEmptyTrackerIsDeletedWithItsRulesAndTheOthersCloseRanks(): void
    {
        $support = $this->create('Support');
        $last = $this->create('Last');
        $manager = (int) $this->fixtures->manager->getId();
        $rule = $this->commands->dispatch(new CreateRule((int) $this->fixtures->access->shop->getId(), 'Greets', (int) $support->getId(), [
            'trigger' => ['type' => 'issue_created', 'params' => []],
            'conditions' => [],
            'actions' => [['type' => 'set_criticality', 'params' => ['criticality' => 'high']]],
        ], actorId: $manager));
        self::assertInstanceOf(AutomationRule::class, $rule);
        $ruleId = (int) $rule->getId();
        $supportId = (int) $support->getId();

        $this->commands->dispatch(new DeleteTracker($manager, $supportId));

        self::assertNull($this->trackers->find($supportId));
        self::assertNull(self::getContainer()->get(AutomationRuleRepository::class)->find($ruleId));
        self::assertSame(['Bug', 'Internal task', 'Last'], $this->names());
        self::assertSame(2, $last->getPosition());
    }

    public function testATrackerThatHoldsIssuesIsNotDeleted(): void
    {
        $this->fixtures->createIssue($this->fixtures->client);

        try {
            $this->commands->dispatch(new DeleteTracker((int) $this->fixtures->developer->getId(), (int) $this->fixtures->bugs->getId()));
            self::fail('A developer does not configure the project.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(TrackerException::class);
        $this->expectExceptionMessage('holds 1 issues');
        $this->commands->dispatch(new DeleteTracker((int) $this->fixtures->manager->getId(), (int) $this->fixtures->bugs->getId()));
    }

    public function testMovingATrackerNumbersTheListAgain(): void
    {
        $support = $this->create('Support');

        $this->commands->dispatch(new UpdateTracker((int) $this->fixtures->manager->getId(), (int) $support->getId(), position: 0));
        self::assertSame(['Support', 'Bug', 'Internal task'], $this->names());

        $this->commands->dispatch(new UpdateTracker((int) $this->fixtures->manager->getId(), (int) $support->getId(), position: 9));
        self::assertSame(['Bug', 'Internal task', 'Support'], $this->names());
        self::assertSame([0, 1, 2], array_map(static fn (Tracker $tracker): int => $tracker->getPosition(), $this->all()));
    }

    private function create(string $name, string $workflowId = 'bug', bool $private = false): Tracker
    {
        $tracker = $this->commands->dispatch(new CreateTracker((int) $this->fixtures->manager->getId(), (int) $this->fixtures->access->shop->getId(), $name, $workflowId, 'For the questions.', $private));
        self::assertInstanceOf(Tracker::class, $tracker);

        return $tracker;
    }

    private function update(?string $name = null, ?string $description = null, ?bool $private = null, ?bool $archived = null): void
    {
        $this->commands->dispatch(new UpdateTracker((int) $this->fixtures->manager->getId(), (int) $this->fixtures->bugs->getId(), $name, $description, $private, $archived));
    }

    /**
     * @return list<Tracker>
     */
    private function all(): array
    {
        return $this->trackers->findByProject($this->fixtures->access->shop, includeArchived: true);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(static fn (Tracker $tracker): string => $tracker->getName(), $this->all());
    }
}
