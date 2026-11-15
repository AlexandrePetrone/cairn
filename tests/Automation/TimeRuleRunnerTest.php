<?php

declare(strict_types=1);

namespace App\Tests\Automation;

use App\Automation\TimeRuleRunner;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\Tracker;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The rules that wait for time, on the shop: a reminder after 30 days
 * without activity on an issue left with the client, then its closing
 * after 45. Thomas manages, Nadia develops, Claire is the client.
 */
final class TimeRuleRunnerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use KernelHelpers;

    private const int DAY = 86_400;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private EntityManagerInterface $entityManager;
    private TimeRuleRunner $runner;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->clock = self::mockTime('2026-01-05 10:00:00');
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
        $this->runner = self::getContainer()->get(TimeRuleRunner::class);

        $waitingForTheClient = [['type' => 'assignee', 'params' => ['is' => 'external']], ['type' => 'code', 'params' => ['is' => 'none']]];
        $this->rule('Reminder', 30, $waitingForTheClient, [['type' => 'comment', 'params' => ['body' => 'Any news? Without an answer this issue will be closed.']]]);
        $this->rule('Closing', 45, $waitingForTheClient, [['type' => 'move', 'params' => ['place' => 'done']]], $this->fixtures->bugs);
    }

    public function testAnIssueLeftWithTheClientIsRemindedOnceThenClosed(): void
    {
        $left = $this->issueAssignedTo((int) $this->fixtures->client->getId());
        $answered = $this->issueAssignedTo((int) $this->fixtures->client->getId());
        $inHouse = $this->issueAssignedTo((int) $this->fixtures->developer->getId());

        $this->clock->sleep(29 * self::DAY);
        self::assertSame(0, $this->runner->runDue(), 'nothing is due before the thirtieth day');

        $this->clock->sleep(2 * self::DAY);
        self::assertSame(2, $this->runner->runDue(dryRun: true));
        self::assertSame([], $this->ruleComments($left), 'a dry run runs nothing');
        self::assertSame(2, $this->runner->runDue());
        self::assertSame(['Reminder'], $this->ruleComments($left));
        self::assertSame(['Reminder'], $this->ruleComments($answered));
        self::assertSame([], $this->ruleComments($inHouse), 'an issue an internal user holds is not the client\'s to answer');
        self::assertSame('2026-01-05', $this->issue($left)->getLastActivityAt()->format('Y-m-d'), 'what a rule wrote is not someone acting');
        self::assertSame(0, $this->runner->runDue(), 'a rule acts once, then waits for someone');

        $this->clock->sleep(4 * self::DAY);
        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $this->issue($answered)->getKey(), 'Still needed, we are checking.'));

        $this->clock->sleep(11 * self::DAY);
        self::assertSame(1, $this->runner->runDue(), 'day 46: the issue nobody answered is closed');
        self::assertSame('done', $this->issue($left)->getPlace());
        self::assertNotNull($this->issue($left)->getClosedAt());
        self::assertSame('new', $this->issue($answered)->getPlace(), 'an answer starts the count again');

        $this->clock->sleep(20 * self::DAY);
        self::assertSame(1, $this->runner->runDue(), 'day 66: thirty days after the answer, a second reminder');
        self::assertSame(['Reminder', 'Reminder'], $this->ruleComments($answered));
        self::assertSame(['Reminder'], $this->ruleComments($left), 'a closed issue is left in peace');

        $this->clock->sleep(15 * self::DAY);
        self::assertSame(1, $this->runner->runDue());
        self::assertSame('done', $this->issue($answered)->getPlace());
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param list<array<string, mixed>> $actions
     */
    private function rule(string $name, int $days, array $conditions, array $actions, ?Tracker $tracker = null): void
    {
        $this->commands->dispatch(new CreateRule(
            (int) $this->fixtures->access->shop->getId(),
            $name,
            $tracker?->getId(),
            ['trigger' => ['type' => 'inactive_for', 'params' => ['days' => $days]], 'conditions' => $conditions, 'actions' => $actions],
        ));
    }

    /**
     * @return int the id of the issue: the runner clears the unit of work, so issues are read again by id
     */
    private function issueAssignedTo(int $userId): int
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), assigneeId: $userId));

        return (int) $issue->getId();
    }

    private function issue(int $id): Issue
    {
        $issue = $this->entityManager->getRepository(Issue::class)->find($id);
        self::assertNotNull($issue);

        return $issue;
    }

    /**
     * @return list<string> the rule behind each comment written without an author, oldest first
     */
    private function ruleComments(int $issueId): array
    {
        $comments = $this->entityManager->getRepository(Comment::class)->findBy(['issue' => $issueId, 'author' => null], ['id' => 'ASC']);

        return array_map(static fn (Comment $comment): string => (string) $comment->getSignature(), array_values($comments));
    }
}
