<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Enum\PlaceStatus;
use App\Entity\JournalEntry;
use App\Message\CommandBus;
use App\Message\Issue\ApplyTransition;
use App\MessageHandler\Issue\IssueException;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Test workflow: new -(start, developer)-> in_progress -(deliver)-> to_test
 * -(accept, client/pm | refuse with comment, client)-> done / in_progress.
 */
final class ApplyTransitionHandlerTest extends KernelTestCase
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

    public function testATransitionMovesTheIssueAndIsJournaled(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start'));

        self::assertSame('in_progress', $issue->getPlace());
        self::assertSame(PlaceStatus::InProgress, $issue->getStatus());

        $entry = $this->lastEntry($issue->getId());
        self::assertSame(JournalEntryType::Transitioned, $entry->getType());
        self::assertSame(['transition' => 'Démarrer', 'from' => 'Nouveau', 'from_id' => 'new', 'to' => 'En cours', 'to_id' => 'in_progress'], $entry->getData());
        self::assertNull($entry->getComment());
    }

    public function testTheRoleMustBeAllowedByTheTransition(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->client->getId(), $issue->getKey(), 'start'));
    }

    public function testTheTransitionMustLeaveTheCurrentPlace(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('cannot be applied from place "new"');
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->manager->getId(), $issue->getKey(), 'accept'));
    }

    public function testARequiredCommentIsStoredAndLinkedToTheEntry(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = (int) $this->fixtures->developer->getId();
        $this->commands->dispatch(new ApplyTransition($developer, $issue->getKey(), 'start'));
        $this->commands->dispatch(new ApplyTransition($developer, $issue->getKey(), 'deliver'));

        try {
            $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->client->getId(), $issue->getKey(), 'refuse'));
            self::fail('A comment is required');
        } catch (IssueException $e) {
            self::assertStringContainsString('requires a comment', $e->getMessage());
        }

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->client->getId(), $issue->getKey(), 'refuse', comment: 'Toujours vide en production.'));

        self::assertSame('in_progress', $issue->getPlace());
        $entry = $this->lastEntry($issue->getId());
        $comment = $entry->getComment();
        self::assertNotNull($comment);
        self::assertSame('Toujours vide en production.', $comment->getBody());
        self::assertFalse($entry->isPrivate());
        self::assertSame($this->fixtures->client->getId(), $comment->getAuthor()->getId());
    }

    public function testClosingSetsTheClosingDate(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = (int) $this->fixtures->developer->getId();
        $this->commands->dispatch(new ApplyTransition($developer, $issue->getKey(), 'start'));
        $this->commands->dispatch(new ApplyTransition($developer, $issue->getKey(), 'deliver'));
        self::assertNull($issue->getClosedAt());

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->manager->getId(), $issue->getKey(), 'accept'));

        self::assertSame(PlaceStatus::Closed, $issue->getStatus());
        self::assertNotNull($issue->getClosedAt());
    }

    public function testAnInvisibleIssueIsNotFound(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->developer, $this->fixtures->tasks);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('not found');
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->client->getId(), $issue->getKey(), 'start'));
    }

    private function lastEntry(?int $issueId): JournalEntry
    {
        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issueId], ['id' => 'DESC']);
        self::assertNotNull($entry);

        return $entry;
    }
}
