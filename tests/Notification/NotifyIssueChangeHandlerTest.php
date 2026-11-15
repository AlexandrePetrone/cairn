<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\PendingNotification;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\FollowIssue;
use App\Message\CommandBus;
use App\Message\Notification\NotifyIssueChange;
use App\MessageHandler\Notification\NotifyIssueChangeHandler;
use App\Repository\PendingNotificationRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Who a change waits for, read by handing the handler the last message a
 * command left on the in-memory transport. Thomas (manager) and Claire
 * (author) follow every issue of the fixtures; Nadia develops. No mail
 * leaves here: ProjectMailSenderTest reads those.
 */
final class NotifyIssueChangeHandlerTest extends KernelTestCase
{
    use KernelHelpers;
    use MailerAssertionsTrait;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private InMemoryTransport $async;
    private NotifyIssueChangeHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
        $this->handler = self::getContainer()->get(NotifyIssueChangeHandler::class);
    }

    public function testTheCreationWaitsForTheOtherSubscribers(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->handleLast();

        $waiting = $this->waiting();
        self::assertSame(['thomas@agency.test'], $this->recipients($waiting), 'the author does not wait for their own creation');
        self::assertSame($issue->getId(), $waiting[0]->getIssue()->getId());
        self::assertSame($this->fixtures->client->getId(), $waiting[0]->getActor()?->getId());
        self::assertCount(1, $waiting[0]->getEntryIds());
        self::assertNull($waiting[0]->getCommentId());
        self::assertEmailCount(0);
    }

    public function testAPrivateCommentWaitsForInternalSubscribersOnly(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new FollowIssue((int) $this->fixtures->developer->getId(), $issue->getKey()));
        $this->commands->dispatch(new AddComment((int) $this->fixtures->manager->getId(), $issue->getKey(), 'Between us: **urgent**.', private: true));

        $this->handleLast();

        $waiting = $this->waiting();
        self::assertSame(['nadia@agency.test'], $this->recipients($waiting), 'Claire is external, Thomas acted');
        self::assertSame([], $waiting[0]->getEntryIds());
        self::assertNotNull($waiting[0]->getCommentId());
    }

    public function testAnActionMadeWithAPrivateCommentWaitsForInternalSubscribersOnly(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start', comment: 'I am on it, the client does not read this.', commentPrivate: true));

        $this->handleLast();

        // The journal line of such an action is private too: the client reads the new place on the issue.
        self::assertSame(['thomas@agency.test'], $this->recipients($this->waiting()));
    }

    public function testAnActionWithAPublicCommentWaitsForEverySubscriber(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start', comment: 'I am on it.'));

        $this->handleLast();

        self::assertSame(['claire@client.test', 'thomas@agency.test'], $this->recipients($this->waiting()));
    }

    private function handleLast(): void
    {
        $sent = $this->async->getSent();
        $envelope = end($sent);
        self::assertInstanceOf(Envelope::class, $envelope);
        $message = $envelope->getMessage();
        self::assertInstanceOf(NotifyIssueChange::class, $message);
        ($this->handler)($message);
    }

    /**
     * @return list<PendingNotification>
     */
    private function waiting(): array
    {
        return self::getContainer()->get(PendingNotificationRepository::class)->findWaiting();
    }

    /**
     * @param list<PendingNotification> $waiting
     *
     * @return list<string>
     */
    private function recipients(array $waiting): array
    {
        $emails = array_map(static fn (PendingNotification $row): string => $row->getRecipient()->getEmail(), $waiting);
        sort($emails);

        return $emails;
    }
}
