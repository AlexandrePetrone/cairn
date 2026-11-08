<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Entity\IssueSubscription;
use App\Message\Command\Issue\FollowIssue;
use App\Message\Command\Issue\UnfollowIssue;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Repository\IssueSubscriptionRepository;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FollowIssueHandlerTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private IssueSubscriptionRepository $subscriptions;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $this->subscriptions = self::getContainer()->get(IssueSubscriptionRepository::class);
    }

    public function testADeveloperFollowsAndStopsFollowingAnIssue(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = $this->fixtures->developer;
        self::assertNull($this->subscriptions->findOne($issue, $developer), 'a developer is not subscribed by default');

        $this->commands->dispatch(new FollowIssue((int) $developer->getId(), $issue->getKey()));
        $this->commands->dispatch(new FollowIssue((int) $developer->getId(), $issue->getKey()));
        self::assertInstanceOf(IssueSubscription::class, $this->subscriptions->findOne($issue, $developer));
        self::assertCount(1, $this->subscriptions->findBy(['issue' => $issue, 'user' => $developer]), 'following twice makes one subscription');

        $this->commands->dispatch(new UnfollowIssue((int) $developer->getId(), $issue->getKey()));
        $this->commands->dispatch(new UnfollowIssue((int) $developer->getId(), $issue->getKey()));
        self::assertNull($this->subscriptions->findOne($issue, $developer));
    }

    public function testAnInvisibleIssueCannotBeFollowed(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('not found');
        $this->commands->dispatch(new FollowIssue((int) $this->fixtures->client->getId(), $issue->getKey()));
    }
}
