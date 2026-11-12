<?php

declare(strict_types=1);

namespace App\Tests\Realtime;

use App\Entity\Issue;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * With a hub, a command that changed an issue leaves Mercure updates on
 * the `async` transport (in memory in the test environment): for
 * internal users, and for everyone only when an external user may see the
 * change. Without a hub, which is how the other tests run, nothing.
 */
final class IssueBroadcasterTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private InMemoryTransport $async;

    protected function setUp(): void
    {
        // The hub is read from the environment when the kernel boots: one test runs without.
        $_ENV['MERCURE_URL'] = $_SERVER['MERCURE_URL'] = 'testWithoutAHubNothingIsPublished' === $this->name() ? '' : 'http://hub.test/.well-known/mercure';
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
    }

    protected function tearDown(): void
    {
        $_ENV['MERCURE_URL'] = $_SERVER['MERCURE_URL'] = '';
        parent::tearDown();
    }

    public function testAChangeEveryoneSeesIsPublishedToBothAudiencesWithoutContent(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $from = $issue->getPlace();
        $this->async->reset();

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start'));

        self::assertNotSame($from, $issue->getPlace());
        $change = ['issue' => $issue->getId(), 'tracker' => $issue->getTracker()->getId(), 'place' => $issue->getPlace(), 'from' => $from];
        self::assertSame([$this->internal($issue) => $change, $this->everyone($issue) => $change], $this->published());
        foreach ($this->updates() as $update) {
            self::assertTrue($update->isPrivate());
        }
    }

    public function testAPrivateCommentIsForInternalUsersOnly(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->async->reset();

        $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), 'For everyone.'));

        self::assertSame([$this->internal($issue), $this->everyone($issue)], array_keys($this->published()));
        $this->async->reset();

        $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), 'Between us.', private: true));

        self::assertSame([$this->internal($issue)], array_keys($this->published()));
    }

    public function testATransitionWithAPrivateCommentStillMovesTheIssueForEveryone(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->async->reset();

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start', comment: 'Between us.', commentPrivate: true));

        self::assertSame([$this->internal($issue), $this->everyone($issue)], array_keys($this->published()));
    }

    public function testAPrivateIssueIsForInternalUsersOnlyOnceItLeftTheSightOfTheOthers(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->async->reset();

        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), private: true));

        // Made private: the others are told its id alone, so that it leaves their screen.
        self::assertSame(['issue' => $issue->getId()], $this->published()[$this->everyone($issue)]);
        $this->async->reset();

        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), title: 'Renamed in private'));

        self::assertSame([$this->internal($issue)], array_keys($this->published()));
    }

    public function testARefusedCommandPublishesNothing(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->async->reset();

        try {
            $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'no_such_action'));
            self::fail('The transition does not exist.');
        } catch (\Throwable) {
        }

        self::assertSame([], $this->published());
    }

    public function testWithoutAHubNothingIsPublished(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start'));

        self::assertSame([], $this->published());
    }

    private function internal(Issue $issue): string
    {
        return \sprintf('projects/%d/internal', $issue->getProject()->getId());
    }

    private function everyone(Issue $issue): string
    {
        return \sprintf('projects/%d', $issue->getProject()->getId());
    }

    /**
     * @return list<Update>
     */
    private function updates(): array
    {
        return array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->async->getSent()),
            static fn (object $message): bool => $message instanceof Update,
        ));
    }

    /**
     * What was published, by topic.
     *
     * @return array<string, array<string, mixed>>
     */
    private function published(): array
    {
        $published = [];
        foreach ($this->updates() as $update) {
            foreach ($update->getTopics() as $topic) {
                $published[$topic] = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            }
        }

        return $published;
    }
}
