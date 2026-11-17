<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\JournalEntry;
use App\Entity\Tracker;
use App\Message\Command\Issue\ChangeTracker;
use App\Message\Command\Issue\MoveIssue;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class MoveIssueHandlerTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
    }

    public function testTheIssueMovesToTheChosenPlaceOfTheNewTrackerAndKeepsItsFields(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->commands->dispatch(new ChangeTracker((int) $this->fixtures->developer->getId(), $issue->getKey(), (int) $this->fixtures->tasks->getId(), 'in_progress'));

        self::assertSame($this->fixtures->tasks->getId(), $issue->getTracker()->getId());
        self::assertSame('in_progress', $issue->getPlace());
        self::assertTrue($issue->isPrivate(), 'the new tracker is private');
        self::assertSame(['environment' => 'prod'], $issue->getFieldValues());

        $entry = $this->lastEntry($issue);
        self::assertSame(JournalEntryType::TrackerChanged, $entry->getType());
        self::assertSame(['from_tracker' => 'Bug', 'from' => 'New', 'from_id' => 'new', 'to_tracker' => 'Internal task', 'to' => 'In progress', 'to_id' => 'in_progress'], $entry->getData());
    }

    public function testClientsCannotChangeTheTracker(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new ChangeTracker((int) $this->fixtures->client->getId(), $issue->getKey(), (int) $this->fixtures->tasks->getId(), 'new'));
    }

    public function testThePlaceMustExistInTheNewWorkflow(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('Place "nowhere"');
        $this->commands->dispatch(new ChangeTracker((int) $this->fixtures->developer->getId(), $issue->getKey(), (int) $this->fixtures->tasks->getId(), 'nowhere'));
    }

    public function testTheTrackerMustBelongToTheSameProject(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $elsewhere = new Tracker($this->fixtures->access->otherProject, 'Elsewhere', DefinitionFactory::workflowRow('bug', $this->entityManager));
        $this->entityManager->persist($elsewhere);
        $this->entityManager->flush();

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('does not belong');
        $this->commands->dispatch(new ChangeTracker((int) $this->fixtures->developer->getId(), $issue->getKey(), (int) $elsewhere->getId(), 'new'));
    }

    public function testAManagerPutsTheIssueOnAnyPlaceWithoutAnAction(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new MoveIssue($manager, $issue->getKey(), 'to_test'));

        self::assertSame('to_test', $issue->getPlace());
        self::assertSame($this->fixtures->bugs->getId(), $issue->getTracker()->getId());
        self::assertNull($issue->getClosedAt());
        $entry = $this->lastEntry($issue);
        self::assertSame(JournalEntryType::Moved, $entry->getType());
        self::assertSame(['from' => 'New', 'from_id' => 'new', 'to' => 'To test', 'to_id' => 'to_test'], $entry->getData());
        self::assertFalse($entry->isPrivate());
    }

    public function testTheTrackerMustChange(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('already in tracker');
        $this->commands->dispatch(new ChangeTracker((int) $this->fixtures->developer->getId(), $issue->getKey(), (int) $this->fixtures->bugs->getId(), 'new'));
    }

    public function testTheWorkflowComesFirstWhenAnActionOfTheActorLeadsThere(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();
        $this->commands->dispatch(new MoveIssue($manager, $issue->getKey(), 'to_test'));

        $this->commands->dispatch(new MoveIssue($manager, $issue->getKey(), 'done'));

        self::assertSame('done', $issue->getPlace());
        self::assertNotNull($issue->getClosedAt());
        $entry = $this->lastEntry($issue);
        self::assertSame(JournalEntryType::Transitioned, $entry->getType(), '"Accept" leads there: the action is applied, not the place forced');
        self::assertSame('Accept', $entry->getData()['transition'] ?? null);
    }

    public function testAnActionIsEnoughToMoveWithoutTheRightToForce(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->commands->dispatch(new MoveIssue((int) $this->fixtures->developer->getId(), $issue->getKey(), 'in_progress'));

        self::assertSame('in_progress', $issue->getPlace());
        self::assertSame(JournalEntryType::Transitioned, $this->lastEntry($issue)->getType(), '"Start" is a developer\'s action');
    }

    public function testForcingAPlaceIsARightOfItsOwn(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new MoveIssue((int) $this->fixtures->developer->getId(), $issue->getKey(), 'to_test'));
    }

    public function testThePlaceMustExistInTheWorkflow(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('Place "nowhere"');
        $this->commands->dispatch(new MoveIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), 'nowhere'));
    }

    public function testTheIssueMustLeaveItsPlace(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('already on');
        $this->commands->dispatch(new MoveIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), 'new'));
    }

    private function lastEntry(\App\Entity\Issue $issue): JournalEntry
    {
        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue], ['id' => 'DESC']);
        self::assertNotNull($entry);

        return $entry;
    }
}
