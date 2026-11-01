<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Attachment;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;
use App\Message\Command\Attachment\RemoveAttachment;
use App\Message\Command\Attachment\UploadAttachment;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ChangeDescription;
use App\Message\Command\Issue\CreateIssue;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Repository\AttachmentRepository;
use App\Security\AccessControl;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * UploadAttachment, RemoveAttachment, and the commands that claim pending
 * files. Every role of the test configuration may create, edit and
 * comment; an internal user without a role sees everything and acts
 * nowhere.
 */
final class AttachmentHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;

    /** @var list<string> temporary files a refused upload leaves behind */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testAnyFileUpTo10MegabytesIsAccepted(): void
    {
        $pdf = $this->upload($this->fixtures->client, $this->file('devis.pdf', '%PDF-1.4'));
        self::assertSame('devis.pdf', $pdf->getFilename());
        self::assertTrue($pdf->isPending());
        self::assertFalse($pdf->isImage());

        $this->expectException(ValidationFailedException::class);
        $this->upload($this->fixtures->client, $this->file('dump.log', size: 10 * 1024 * 1024 + 1));
    }

    public function testTheFilesOfTheIssueFollowTheRightToEditIt(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $file = $this->upload($this->fixtures->client, $this->file('export.log', 'ERROR'), $issue);

        $byManager = $this->upload($this->fixtures->manager, $this->file('autre.log', 'x'), $issue);
        self::assertSame($issue, $byManager->getIssue(), 'issue.edit covers the files of the issue');
        $this->commands->dispatch(new RemoveAttachment((int) $this->fixtures->manager->getId(), $byManager->getUuid()->toRfc4122()));

        try {
            $this->upload($this->fixtures->developer, $this->file('encore.log', 'x'), $issue);
            self::fail('the developer did not write the issue and may not edit the issues of others');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new RemoveAttachment((int) $this->fixtures->developer->getId(), $file->getUuid()->toRfc4122()));
    }

    public function testAFileAddedToTheIssueIsAnEditAndIsJournaled(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $attachment = $this->upload($this->fixtures->client, $this->file('export.log', 'ERROR'), $issue);

        self::assertSame($issue, $attachment->getIssue());
        self::assertNull($attachment->getComment());
        $entry = $this->lastEntry($issue);
        self::assertSame(JournalEntryType::AttachmentAdded, $entry->getType());
        self::assertSame(['filename' => 'export.log'], $entry->getData());

        $this->expectException(AccessDeniedException::class);
        $this->upload($this->onlooker(), $this->file('export.log', 'ERROR'), $issue);
    }

    public function testSendingNeedsTheRightToWriteInTheProject(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->upload($this->onlooker(), $this->file('export.log', 'ERROR'));
    }

    public function testAPrivateCommentTakesItsFilesAndTheImagesItsTextPointsTo(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = $this->fixtures->developer;
        $sent = $this->upload($developer, $this->file('trace.log', 'stack'));
        $pasted = $this->upload($developer, $this->file('capture.png', self::png()));
        $someoneElses = $this->upload($this->fixtures->manager, $this->file('autre.log', 'x'));

        $comment = $this->commands->dispatch(new AddComment(
            (int) $developer->getId(),
            $issue->getKey(),
            \sprintf('Voir la capture ![capture](/attachments/%s/capture.png)', $pasted->getUuid()->toRfc4122()),
            private: true,
            attachments: [$sent->getUuid()->toRfc4122(), $someoneElses->getUuid()->toRfc4122()],
        ));
        self::assertInstanceOf(Comment::class, $comment);

        self::assertSame($comment, $sent->getComment());
        self::assertSame($comment, $pasted->getComment());
        self::assertTrue($someoneElses->isPending(), 'a file sent by someone else is never claimed');

        $access = self::getContainer()->get(AccessControl::class);
        self::assertTrue($access->canSeeAttachment($this->fixtures->manager, $sent));
        self::assertFalse($access->canSeeAttachment($this->fixtures->client, $sent), 'the files of a private comment are for internals');
        self::assertFalse($access->canSeeAttachment($this->fixtures->client, $pasted));
    }

    public function testTheIssueTakesTheFilesOfItsCreationAndOfItsDescription(): void
    {
        $client = $this->fixtures->client;
        $sent = $this->upload($client, $this->file('brief.pdf', '%PDF'));
        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $client->getId(),
            projectId: (int) $this->fixtures->bugs->getProject()->getId(),
            trackerId: (int) $this->fixtures->bugs->getId(),
            title: 'Export CSV vide',
            description: 'Voir le brief.',
            fields: ['environment' => 'prod'],
            attachments: [$sent->getUuid()->toRfc4122()],
        ));
        self::assertInstanceOf(Issue::class, $issue);
        self::assertSame($issue, $sent->getIssue());

        $pasted = $this->upload($client, $this->file('capture.png', self::png()));
        $this->commands->dispatch(new ChangeDescription((int) $client->getId(), $issue->getKey(), \sprintf('![capture](/attachments/%s/capture.png)', $pasted->getUuid()->toRfc4122())));
        self::assertSame($issue, $pasted->getIssue());
        self::assertNull($pasted->getComment());
    }

    public function testRemovingDeletesTheFileAndTheRowAndKeepsItsName(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = $this->fixtures->developer;
        $file = $this->upload($developer, $this->file('trace.log', 'stack'));
        $this->commands->dispatch(new AddComment((int) $developer->getId(), $issue->getKey(), 'Trace', private: true, attachments: [$file->getUuid()->toRfc4122()]));
        $uuid = $file->getUuid();
        $path = self::getContainer()->get(AttachmentStorage::class)->path($file);
        self::assertFileExists($path);

        $this->commands->dispatch(new RemoveAttachment((int) $developer->getId(), $uuid->toRfc4122()));
        $this->entityManager->flush();

        self::assertFileDoesNotExist($path);
        self::assertNull(self::getContainer()->get(AttachmentRepository::class)->findOneByUuid($uuid));
        $entry = $this->lastEntry($issue);
        self::assertSame(JournalEntryType::AttachmentRemoved, $entry->getType());
        self::assertSame(['filename' => 'trace.log'], $entry->getData());
        self::assertTrue($entry->isPrivate(), 'the file came with a private comment');
    }

    public function testTheFilesOfACommentFollowTheRightToEditIt(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = $this->fixtures->developer;
        $file = $this->upload($developer, $this->file('trace.log', 'stack'));
        $this->commands->dispatch(new AddComment((int) $developer->getId(), $issue->getKey(), 'Trace', private: true, attachments: [$file->getUuid()->toRfc4122()]));

        try {
            $this->commands->dispatch(new RemoveAttachment((int) $this->fixtures->client->getId(), $file->getUuid()->toRfc4122()));
            self::fail('a client cannot reach the file of a private comment');
        } catch (IssueException) {
        }

        try {
            $this->commands->dispatch(new RemoveAttachment((int) $this->onlooker()->getId(), $file->getUuid()->toRfc4122()));
            self::fail('an internal user without a role sees the comment but did not write it');
        } catch (AccessDeniedException) {
        }

        $this->commands->dispatch(new RemoveAttachment((int) $this->fixtures->manager->getId(), $file->getUuid()->toRfc4122()));
        self::assertNull(self::getContainer()->get(AttachmentRepository::class)->findOneByUuid($file->getUuid()), 'comment.edit covers the files of the comment');
    }

    /** A 1×1 transparent PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private static function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    private function upload(User $actor, UploadedFile $file, ?Issue $issue = null): Attachment
    {
        $attachment = $this->commands->dispatch(new UploadAttachment((int) $actor->getId(), (int) $this->fixtures->bugs->getProject()->getId(), $issue?->getKey(), $file));
        self::assertInstanceOf(Attachment::class, $attachment);

        return $attachment;
    }

    /**
     * @param int<0, max>|null $size
     */
    private function file(string $name, string $content = '', ?int $size = null): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cairn');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $content);
        if (null !== $size) {
            $handle = fopen($path, 'r+');
            self::assertNotFalse($handle);
            ftruncate($handle, $size);
            fclose($handle);
        }

        return new UploadedFile($path, $name, null, null, true);
    }

    /** An internal user without a role: sees everything, acts nowhere. */
    private function onlooker(): User
    {
        return $this->fixtures->access->user('lea@agency.test', internal: true);
    }

    private function lastEntry(Issue $issue): JournalEntry
    {
        return $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue], ['id' => 'DESC']) ?? self::fail('no journal entry');
    }
}
