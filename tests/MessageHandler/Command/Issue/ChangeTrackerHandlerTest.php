<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\ChangeTracker;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ChangeTrackerHandlerTest extends KernelTestCase
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

        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue], ['id' => 'DESC']);
        self::assertNotNull($entry);
        self::assertSame(JournalEntryType::TrackerChanged, $entry->getType());
        self::assertSame(['from_tracker' => 'Anomalie', 'from' => 'Nouveau', 'from_id' => 'new', 'to_tracker' => 'Tâche interne', 'to' => 'En cours', 'to_id' => 'in_progress'], $entry->getData());
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
        $elsewhere = new \App\Entity\Tracker($this->fixtures->access->otherProject, 'Ailleurs', 'bug');
        $this->entityManager->persist($elsewhere);
        $this->entityManager->flush();

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('does not belong');
        $this->commands->dispatch(new ChangeTracker((int) $this->fixtures->developer->getId(), $issue->getKey(), (int) $elsewhere->getId(), 'new'));
    }
}
