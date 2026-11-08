<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;
use App\Message\Command\Attachment\UploadAttachment;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\DeleteComment;
use App\Message\Command\Issue\EditComment;
use App\Message\CommandBus;
use App\Repository\AttachmentRepository;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * EditComment and DeleteComment: the comment's author, or a project
 * manager (comment.edit in the test configuration). The developer who
 * wrote nothing is the refused case.
 */
final class CommentEditHandlersTest extends KernelTestCase
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

    public function testTheAuthorRewritesTheirCommentAndBothVersionsAreJournaled(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $client = $this->fixtures->client;
        $comment = $this->comment($client, $issue, 'Thanks, it is blocking.');
        $file = $this->upload($client, 'quote.pdf');

        $this->commands->dispatch(new EditComment((int) $client->getId(), (int) $comment->getId(), '  Thanks, it is blocking our teams.  ', [$file->getUuid()->toRfc4122()]));

        self::assertSame('Thanks, it is blocking our teams.', $comment->getBody());
        self::assertNotNull($comment->getEditedAt());
        self::assertSame($comment, $file->getComment(), 'a file sent while rewriting goes with the comment');
        $entry = $this->lastEntry($issue);
        self::assertSame(JournalEntryType::CommentEdited, $entry->getType());
        self::assertSame(['from' => 'Thanks, it is blocking.', 'to' => 'Thanks, it is blocking our teams.', 'author' => $client->getFullName(), 'author_id' => $client->getId()], $entry->getData());
        self::assertNull($entry->getComment(), 'not linked, or the comment would move to the entry');
        self::assertFalse($entry->isPrivate());

        $this->commands->dispatch(new EditComment((int) $client->getId(), (int) $comment->getId(), 'Thanks, it is blocking our teams.'));
        self::assertSame($entry, $this->lastEntry($issue), 'the same text journals nothing');
    }

    public function testAManagerRewritesOthersCommentsAndADeveloperDoesNot(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $comment = $this->comment($this->fixtures->client, $issue, 'Thanks.');

        $this->commands->dispatch(new EditComment((int) $this->fixtures->manager->getId(), (int) $comment->getId(), 'Thanks (corrected).'));
        self::assertSame('Thanks (corrected).', $comment->getBody());

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new EditComment((int) $this->fixtures->developer->getId(), (int) $comment->getId(), 'Not mine.'));
    }

    public function testAPrivateCommentEditIsJournaledPrivately(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = $this->fixtures->developer;
        $comment = $this->comment($developer, $issue, 'Estimate: 2 days.', private: true);

        $this->commands->dispatch(new EditComment((int) $developer->getId(), (int) $comment->getId(), 'Estimate: 3 days.'));

        self::assertTrue($this->lastEntry($issue)->isPrivate());
    }

    public function testDeletingKeepsTheTextInTheJournalAndRemovesTheFiles(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = $this->fixtures->developer;
        $file = $this->upload($developer, 'trace.log');
        $comment = $this->comment($developer, $issue, 'See the trace.', private: true, attachments: [$file->getUuid()->toRfc4122()]);
        $commentId = (int) $comment->getId();
        $path = self::getContainer()->get(AttachmentStorage::class)->path($file);
        $uuid = $file->getUuid();

        $this->commands->dispatch(new DeleteComment((int) $this->fixtures->manager->getId(), $commentId));
        $this->entityManager->flush();

        self::assertNull($this->entityManager->find(Comment::class, $commentId));
        self::assertNull(self::getContainer()->get(AttachmentRepository::class)->findOneByUuid($uuid));
        self::assertFileDoesNotExist($path);
        $entry = $this->lastEntry($issue);
        self::assertSame(JournalEntryType::CommentDeleted, $entry->getType());
        self::assertSame('See the trace.', $entry->getData()['body']);
        self::assertSame($developer->getFullName(), $entry->getData()['author']);
        self::assertTrue($entry->isPrivate(), 'the comment was private');
    }

    public function testDeletingTheCommentOfAnActionKeepsTheActionsEntry(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = $this->fixtures->developer;
        $this->commands->dispatch(new ApplyTransition((int) $developer->getId(), $issue->getKey(), 'start', 'Reproduced on staging.'));
        $comment = $this->entityManager->getRepository(Comment::class)->findOneBy(['issue' => $issue]) ?? self::fail('no comment');
        $transition = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue, 'type' => JournalEntryType::Transitioned]) ?? self::fail('no transition entry');
        self::assertSame($comment, $transition->getComment());

        $this->commands->dispatch(new DeleteComment((int) $developer->getId(), (int) $comment->getId()));
        $this->entityManager->flush();

        self::assertNull($transition->getComment(), 'the entry stays, unlinked');
        self::assertSame('in_progress', $issue->getPlace());
    }

    public function testOnlyTheAuthorOrAManagerDeletes(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $comment = $this->comment($this->fixtures->client, $issue, 'Thanks.');

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new DeleteComment((int) $this->fixtures->developer->getId(), (int) $comment->getId()));
    }

    /**
     * @param list<string> $attachments
     */
    private function comment(User $author, Issue $issue, string $body, bool $private = false, array $attachments = []): Comment
    {
        $comment = $this->commands->dispatch(new AddComment((int) $author->getId(), $issue->getKey(), $body, $private, $attachments));
        self::assertInstanceOf(Comment::class, $comment);

        return $comment;
    }

    private function upload(User $actor, string $name): Attachment
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents($path, 'content of '.$name);
        $attachment = $this->commands->dispatch(new UploadAttachment((int) $actor->getId(), (int) $this->fixtures->bugs->getProject()->getId(), new UploadedFile($path, $name, null, null, true)));
        self::assertInstanceOf(Attachment::class, $attachment);

        return $attachment;
    }

    private function lastEntry(Issue $issue): JournalEntry
    {
        return $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue], ['id' => 'DESC']) ?? self::fail('no journal entry');
    }
}
