<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Message\Command\Issue\AddLink;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Message\Notification\NotifyIssueChange;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A command that changed an issue leaves one NotifyIssueChange on the
 * `async` transport (in memory in the test environment), with the ids the
 * flush gave; a command that changed nothing leaves none.
 */
final class NotificationDispatcherTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private InMemoryTransport $async;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
    }

    public function testATransitionWithACommentIsOneMessageWithItsIds(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->async->reset();

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start', comment: 'I am on it.'));

        $messages = $this->notifications();
        self::assertCount(1, $messages);
        self::assertSame($issue->getId(), $messages[0]->issueId);
        self::assertSame($this->fixtures->developer->getId(), $messages[0]->actorId);
        self::assertCount(1, $messages[0]->entryIds);
        self::assertNotNull($messages[0]->commentId);
    }

    public function testAnUpdateThatChangesNothingNotifiesNobody(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->async->reset();

        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), title: $issue->getTitle()));

        self::assertSame([], $this->notifications());
    }

    public function testLinkingTwoIssuesNotifiesBoth(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $other = $this->fixtures->createIssue($this->fixtures->client, title: 'Other');
        $this->async->reset();

        $this->commands->dispatch(new AddLink((int) $this->fixtures->developer->getId(), $issue->getKey(), 'related', $other->getKey()));

        $messages = $this->notifications();
        self::assertCount(2, $messages);
        self::assertSame([$issue->getId(), $other->getId()], [$messages[0]->issueId, $messages[1]->issueId]);
    }

    /**
     * @return list<NotifyIssueChange>
     */
    private function notifications(): array
    {
        return array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->async->getSent()),
            static fn (object $message): bool => $message instanceof NotifyIssueChange,
        ));
    }
}
