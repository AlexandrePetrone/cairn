<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Entity\JournalEntry;
use App\Entity\Tracker;
use App\Message\Command\Issue\AddLink;
use App\Message\Command\Issue\RemoveLink;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueLinkException;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * AddLink and RemoveLink. Test link types: `related` (symmetric) and
 * `blocks` (Blocks / Blocked by); issue.link for managers and developers.
 */
final class LinkHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;
    private Issue $export;
    private Issue $accounting;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
        $this->export = $this->fixtures->createIssue($this->fixtures->client, title: 'Empty CSV export');
        $this->accounting = $this->fixtures->createIssue($this->fixtures->client, title: 'Accounting export');
    }

    public function testALinkReadsFromEachSideAndIsJournaledOnBoth(): void
    {
        $link = $this->link($this->export, 'blocks', $this->accounting);

        self::assertSame($this->export, $link->getSource());
        self::assertSame($this->accounting, $link->getTarget());
        self::assertSame(['label' => 'Blocks', 'key' => 'MD-SPT-2', 'title' => 'Accounting export'], $this->lastEntry($this->export, JournalEntryType::Linked)->getData());
        $onTarget = $this->lastEntry($this->accounting, JournalEntryType::Linked);
        self::assertSame(['label' => 'Blocked by', 'key' => 'MD-SPT-1', 'title' => 'Empty CSV export'], $onTarget->getData());
        self::assertFalse($onTarget->isPrivate(), 'both issues are public, in the same project');
    }

    public function testASymmetricLinkExistsOnceWhateverTheDirection(): void
    {
        $this->link($this->export, 'related', $this->accounting);
        $this->link($this->export, 'blocks', $this->accounting);
        $this->link($this->accounting, 'blocks', $this->export);

        $this->expectException(IssueLinkException::class);
        $this->link($this->accounting, 'related', $this->export);
    }

    public function testTheSameLinkIsNotMadeTwice(): void
    {
        $this->link($this->export, 'blocks', $this->accounting);

        $this->expectException(IssueLinkException::class);
        $this->link($this->export, 'blocks', $this->accounting);
    }

    public function testAnIssueIsNotLinkedToItselfNorWithAnUnknownType(): void
    {
        foreach ([[$this->export->getKey(), 'related'], [$this->accounting->getKey(), 'duplicates'], ['MD-SPT-99', 'related']] as [$otherKey, $type]) {
            try {
                $this->commands->dispatch(new AddLink((int) $this->fixtures->developer->getId(), $this->export->getKey(), $type, $otherKey));
                self::fail(\sprintf('%s %s is refused', $type, $otherKey));
            } catch (IssueLinkException) {
            }
        }
        self::assertSame(0, $this->entityManager->getRepository(IssueLink::class)->count([]));
    }

    public function testAClientMayNotLink(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new AddLink((int) $this->fixtures->client->getId(), $this->export->getKey(), 'related', $this->accounting->getKey()));
    }

    public function testALinkToAPrivateIssueOrAnotherProjectIsJournaledPrivately(): void
    {
        $task = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks, title: 'Internal task');
        $intranetBugs = new Tracker($this->fixtures->access->intranet, 'Bug', DefinitionFactory::workflowRow('bug', $this->entityManager));
        $this->entityManager->persist($intranetBugs);
        $this->entityManager->flush();
        $intranetBug = $this->fixtures->createIssue($this->fixtures->manager, $intranetBugs, title: 'Broken menu');

        $this->link($this->export, 'related', $task);
        self::assertTrue($this->lastEntry($this->export, JournalEntryType::Linked)->isPrivate(), 'the client must not read the private task');
        self::assertFalse($this->lastEntry($task, JournalEntryType::Linked)->isPrivate(), 'the export is no secret for the readers of the task');

        $this->link($this->export, 'blocks', $intranetBug);
        self::assertTrue($this->lastEntry($this->export, JournalEntryType::Linked)->isPrivate());
        self::assertSame('MD-INT-1', $this->lastEntry($this->export, JournalEntryType::Linked)->getData()['key']);
    }

    public function testRemovingALinkDeletesItAndJournalsBothSides(): void
    {
        $link = $this->link($this->export, 'blocks', $this->accounting);
        $id = (int) $link->getId();

        $this->commands->dispatch(new RemoveLink((int) $this->fixtures->manager->getId(), $id));

        self::assertNull($this->entityManager->find(IssueLink::class, $id));
        self::assertSame('Blocks', $this->lastEntry($this->export, JournalEntryType::Unlinked)->getData()['label']);
        self::assertSame('Blocked by', $this->lastEntry($this->accounting, JournalEntryType::Unlinked)->getData()['label']);
    }

    public function testAClientMayNotRemoveALink(): void
    {
        $link = $this->link($this->export, 'blocks', $this->accounting);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new RemoveLink((int) $this->fixtures->client->getId(), (int) $link->getId()));
    }

    private function link(Issue $issue, string $type, Issue $other): IssueLink
    {
        $link = $this->commands->dispatch(new AddLink((int) $this->fixtures->developer->getId(), $issue->getKey(), $type, strtolower($other->getKey())));
        self::assertInstanceOf(IssueLink::class, $link);

        return $link;
    }

    private function lastEntry(Issue $issue, JournalEntryType $type): JournalEntry
    {
        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue, 'type' => $type], ['id' => 'DESC']);
        self::assertNotNull($entry);

        return $entry;
    }
}
