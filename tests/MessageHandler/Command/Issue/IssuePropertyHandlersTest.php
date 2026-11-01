<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\AssignIssue;
use App\Message\Command\Issue\ChangeCriticality;
use App\Message\Command\Issue\ChangeVisibility;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * AssignIssue, ChangeCriticality and ChangeVisibility. Test permissions:
 * issue.assign for managers and developers, issue.set_criticality for
 * everyone, issue.set_private for managers only.
 */
final class IssuePropertyHandlersTest extends KernelTestCase
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

    public function testAnIssueIsAssignedToAMemberAndBackToNobody(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new AssignIssue($manager, $issue->getKey(), $this->fixtures->developer->getId()));
        self::assertSame($this->fixtures->developer->getId(), $issue->getAssignee()?->getId());
        self::assertSame(['field' => 'assignee', 'from' => null, 'to' => 'Nadia Test'], $this->lastEntry($issue)->getData());

        $this->commands->dispatch(new AssignIssue($manager, $issue->getKey(), null));
        self::assertNull($issue->getAssignee());
        self::assertSame(['field' => 'assignee', 'from' => 'Nadia Test', 'to' => null], $this->lastEntry($issue)->getData());
    }

    public function testOnlyAMemberOfTheProjectMayBeAssigned(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $outsider = $this->fixtures->access->user('paul@other.test');

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('holds no role');
        $this->commands->dispatch(new AssignIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), $outsider->getId()));
    }

    public function testADeactivatedMemberMayNotBeAssigned(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->fixtures->developer->deactivate();
        $this->entityManager->flush();

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('is deactivated');
        $this->commands->dispatch(new AssignIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), $this->fixtures->developer->getId()));
    }

    public function testAClientMayNotAssign(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new AssignIssue((int) $this->fixtures->client->getId(), $issue->getKey(), $this->fixtures->developer->getId()));
    }

    public function testTheCriticalityChangesAndAnUnchangedValueLeavesNoTrace(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $client = (int) $this->fixtures->client->getId();

        $this->commands->dispatch(new ChangeCriticality($client, $issue->getKey(), Criticality::Blocking));
        self::assertSame(Criticality::Blocking, $issue->getCriticality());
        $entry = $this->lastEntry($issue);
        self::assertSame(['field' => 'criticality', 'from' => 'normal', 'to' => 'blocking'], $entry->getData());

        $this->commands->dispatch(new ChangeCriticality($client, $issue->getKey(), Criticality::Blocking));
        self::assertSame($entry->getId(), $this->lastEntry($issue)->getId(), 'nothing journaled');
    }

    public function testAManagerHidesAnIssueAndShowsItAgain(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new ChangeVisibility($manager, $issue->getKey(), true));
        self::assertTrue($issue->isPrivate());
        self::assertSame(['field' => 'visibility', 'from' => 'public', 'to' => 'private'], $this->lastEntry($issue)->getData());

        $this->commands->dispatch(new ChangeVisibility($manager, $issue->getKey(), false));
        self::assertFalse($issue->isPrivate());
    }

    public function testAnIssueOfAPrivateTrackerStaysPrivate(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('are private');
        $this->commands->dispatch(new ChangeVisibility((int) $this->fixtures->manager->getId(), $issue->getKey(), false));
    }

    public function testADeveloperMayNotChangeTheVisibility(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new ChangeVisibility((int) $this->fixtures->developer->getId(), $issue->getKey(), true));
    }

    private function lastEntry(Issue $issue): JournalEntry
    {
        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue], ['id' => 'DESC']);
        self::assertNotNull($entry);
        self::assertSame(JournalEntryType::Changed, $entry->getType());

        return $entry;
    }
}
