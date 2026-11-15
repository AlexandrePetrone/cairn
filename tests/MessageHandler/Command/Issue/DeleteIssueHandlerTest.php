<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Entity\IssueSubscription;
use App\Entity\JournalEntry;
use App\Message\Command\Attachment\AddAttachment;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\AddLink;
use App\Message\Command\Issue\DeleteIssue;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * DeleteIssue. Test permissions: issue.delete for project managers and
 * developers, never for a client.
 */
final class DeleteIssueHandlerTest extends KernelTestCase
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

    public function testADeletedIssueLeavesNothingBehindAndTheOthersAreIntact(): void
    {
        $manager = (int) $this->fixtures->manager->getId();
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $other = $this->fixtures->createIssue($this->fixtures->client, title: 'Stays');
        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $issue->getKey(), 'First.'));
        $this->commands->dispatch(new AddComment($manager, $other->getKey(), 'Kept.'));
        $this->commands->dispatch(new AddLink($manager, $issue->getKey(), 'related', $other->getKey()));
        $file = $this->attach($issue);
        $kept = $this->attach($other);
        $storage = self::getContainer()->get(AttachmentStorage::class);
        $path = $storage->path($file);
        self::assertFileExists($path);
        $issueId = (int) $issue->getId();
        $key = $issue->getKey();

        $this->commands->dispatch(new DeleteIssue((int) $this->fixtures->developer->getId(), $key));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->getRepository(Issue::class)->find($issueId));
        foreach ([Comment::class, JournalEntry::class, IssueSubscription::class, Attachment::class] as $class) {
            self::assertSame(0, $this->entityManager->getRepository($class)->count(['issue' => $issueId]), $class);
        }
        self::assertSame(0, $this->entityManager->getRepository(IssueLink::class)->count([]), 'the link goes with either end');
        self::assertFileDoesNotExist($path, 'the file goes once the transaction committed');

        self::assertFileExists($storage->path($kept));
        unlink($storage->path($kept));
        self::assertSame(1, $this->entityManager->getRepository(Comment::class)->count(['issue' => $other->getId()]));
        self::assertSame($other->getNumber() + 1, $this->fixtures->createIssue($this->fixtures->client)->getNumber(), 'the number of a deleted issue is not given again');
    }

    public function testAClientDoesNotDeleteEvenTheirOwnIssue(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new DeleteIssue((int) $this->fixtures->client->getId(), $issue->getKey()));
    }

    public function testNothingIsDeletedInAnArchivedProject(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->fixtures->access->shop->archive();
        $this->entityManager->flush();

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new DeleteIssue((int) $this->fixtures->manager->getId(), $issue->getKey()));
    }

    public function testAnIssueTheActorDoesNotSeeIsNotFound(): void
    {
        $this->expectException(IssueException::class);
        $this->commands->dispatch(new DeleteIssue((int) $this->fixtures->manager->getId(), 'MD-SPT-999'));
    }

    private function attach(Issue $issue): Attachment
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents($path, '%PDF-1.4');
        $attachment = $this->commands->dispatch(new AddAttachment((int) $this->fixtures->manager->getId(), $issue->getKey(), new UploadedFile($path, 'quote.pdf', null, null, true)));
        self::assertInstanceOf(Attachment::class, $attachment);

        return $attachment;
    }
}
