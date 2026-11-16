<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Tracker;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\ChangeTracker;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\RankIssue;
use App\Message\CommandBus;
use App\Message\Query\Issue\IssueSort;
use App\Message\Query\Issue\SearchIssues;
use App\MessageHandler\Command\Issue\IssueException;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The order of a kanban column: an issue arrives on top, and whoever holds
 * `issue.rank` (the internal roles, in the test configuration) places it by hand.
 */
final class RankIssueHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
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

    public function testAnIssueArrivesOnTopOfTheColumnItEntersWhicheverWay(): void
    {
        [$a, $b, $c] = $this->issues('A', 'B', 'C');
        self::assertSame(['C', 'B', 'A'], $this->column('new'), 'created');

        $developer = (int) $this->fixtures->developer->getId();
        $this->commands->dispatch(new ApplyTransition($developer, $a->getKey(), 'start'));
        $this->commands->dispatch(new ApplyTransition($developer, $b->getKey(), 'start'));
        self::assertSame(['B', 'A'], $this->column('in_progress'), 'by an action');

        $this->commands->dispatch(new MoveIssue((int) $this->fixtures->manager->getId(), $c->getKey(), 'to_test'));
        $this->commands->dispatch(new MoveIssue((int) $this->fixtures->manager->getId(), $c->getKey(), 'in_progress'));
        self::assertSame(['C', 'B', 'A'], $this->column('in_progress'), 'by an action found for the place, or outside the workflow');

        [$task] = $this->issues('Task');
        $this->commands->dispatch(new ChangeTracker($developer, $task->getKey(), (int) $this->fixtures->tasks->getId(), 'new'));
        $this->commands->dispatch(new ChangeTracker($developer, $a->getKey(), (int) $this->fixtures->tasks->getId(), 'new'));
        self::assertSame(['A', 'Task'], $this->column('new', $this->fixtures->tasks), 'from another tracker');
        self::assertSame(['C', 'B'], $this->column('in_progress'));
    }

    public function testAnIssueIsPlacedUnderACardOrOverOne(): void
    {
        [$a, , $c] = $this->issues('A', 'B', 'C');
        $developer = (int) $this->fixtures->developer->getId();

        $this->commands->dispatch(new RankIssue($developer, $a->getKey(), aboveKey: $c->getKey()));
        self::assertSame(['C', 'A', 'B'], $this->column('new'));

        $this->commands->dispatch(new RankIssue($developer, $a->getKey(), belowKey: $c->getKey()));
        self::assertSame(['A', 'C', 'B'], $this->column('new'));

        $this->commands->dispatch(new RankIssue($developer, $a->getKey(), aboveKey: $this->issue('B')->getKey()));
        self::assertSame(['C', 'B', 'A'], $this->column('new'), 'under the last card');
    }

    public function testTheCardAboveDecidesWhenOthersStandBetweenTheTwoTheActorSaw(): void
    {
        [$a, $b, , $d] = $this->issues('A', 'B', 'C', 'D');

        // The actor saw D then B, C being filtered out or hidden from them.
        $this->commands->dispatch(new RankIssue((int) $this->fixtures->developer->getId(), $a->getKey(), $d->getKey(), $b->getKey()));

        self::assertSame(['D', 'A', 'C', 'B'], $this->column('new'));
    }

    public function testRankingIsNotAnActivityOnTheIssue(): void
    {
        self::mockTime('2026-03-01 10:00:00');
        [$a, , $c] = $this->issues('A', 'B', 'C');
        $entries = $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $a]);

        self::mockTime('2026-03-02 10:00:00');
        $this->commands->dispatch(new RankIssue((int) $this->fixtures->developer->getId(), $a->getKey(), belowKey: $c->getKey()));

        self::assertSame('2026-03-01', $a->getUpdatedAt()->format('Y-m-d'));
        self::assertSame($entries, $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $a]));
    }

    public function testAClosedColumnIsArrangedLikeAnyOther(): void
    {
        [$a, $b] = $this->issues('A', 'B');
        $manager = (int) $this->fixtures->manager->getId();
        $this->commands->dispatch(new MoveIssue($manager, $a->getKey(), 'done'));
        $this->commands->dispatch(new MoveIssue($manager, $b->getKey(), 'done'));

        $this->commands->dispatch(new RankIssue($manager, $a->getKey(), belowKey: $b->getKey()));

        self::assertSame(['A', 'B'], $this->column('done'));
    }

    public function testAColumnWithoutRoomBetweenTwoCardsIsRankedAnewInItsOrder(): void
    {
        [$a, $b, $c, $d] = $this->issues('A', 'B', 'C', 'D');
        $a->rankAt(10);
        $b->rankAt(11);
        $c->rankAt(11);
        $this->entityManager->flush();
        $shown = $this->column('new');
        self::assertSame('D', $shown[0]);
        self::assertSame('A', $shown[3]);

        // Between the two cards of the same rank, then between two ranks that follow each other.
        $this->commands->dispatch(new RankIssue((int) $this->fixtures->developer->getId(), $d->getKey(), $this->issue($shown[1])->getKey(), $this->issue($shown[2])->getKey()));
        self::assertSame([$shown[1], 'D', $shown[2], 'A'], $this->column('new'));

        $a->rankAt(10);
        $this->issue($shown[2])->rankAt(11);
        $this->entityManager->flush();
        $this->commands->dispatch(new RankIssue((int) $this->fixtures->developer->getId(), $this->issue($shown[1])->getKey(), $this->issue($shown[2])->getKey(), $a->getKey()));
        self::assertSame(['D', $shown[2], $shown[1], 'A'], $this->column('new'));
    }

    public function testACardDroppedUnderOneOfTwoAtTheSameRankStandsRightUnderIt(): void
    {
        [$a, $b, $c, $d] = $this->issues('A', 'B', 'C', 'D');
        $a->rankAt(10);
        $b->rankAt(11);
        $c->rankAt(11);
        $this->entityManager->flush();
        $shown = $this->column('new');

        // The actor does not see the other card of that rank: only the card above is named.
        $this->commands->dispatch(new RankIssue((int) $this->fixtures->developer->getId(), $d->getKey(), aboveKey: $this->issue($shown[1])->getKey()));

        self::assertSame([$shown[1], 'D', $shown[2], 'A'], $this->column('new'));
    }

    public function testANeighbourOfAnotherColumnIsRefused(): void
    {
        [$a, $b] = $this->issues('A', 'B');
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $b->getKey(), 'start'));

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('not in the kanban column');
        $this->commands->dispatch(new RankIssue((int) $this->fixtures->developer->getId(), $a->getKey(), aboveKey: $b->getKey()));
    }

    public function testRankingNeedsThePermission(): void
    {
        [$a, $b] = $this->issues('A', 'B');

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new RankIssue((int) $this->fixtures->client->getId(), $a->getKey(), belowKey: $b->getKey()));
    }

    public function testAnArchivedProjectKeepsItsOrder(): void
    {
        [$a, $b] = $this->issues('A', 'B');
        $this->fixtures->access->shop->archive();
        $this->entityManager->flush();

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new RankIssue((int) $this->fixtures->manager->getId(), $a->getKey(), belowKey: $b->getKey()));
    }

    /**
     * @return list<Issue> bugs created in that order by the client
     */
    private function issues(string ...$titles): array
    {
        return array_map(fn (string $title): Issue => $this->fixtures->createIssue($this->fixtures->client, title: $title), array_values($titles));
    }

    private function issue(string $title): Issue
    {
        return $this->entityManager->getRepository(Issue::class)->findOneBy(['title' => $title]) ?? throw new \LogicException(\sprintf('No issue "%s".', $title));
    }

    /**
     * The titles of a kanban column, as the board reads them.
     *
     * @return list<string>
     */
    private function column(string $place, ?Tracker $tracker = null): array
    {
        $tracker ??= $this->fixtures->bugs;
        $issues = self::queryBus()->ask(new SearchIssues(
            viewer: $this->fixtures->manager,
            projectId: (int) $tracker->getProject()->getId(),
            place: [$place],
            tracker: [(int) $tracker->getId()],
            sort: IssueSort::Rank,
            order: \SortDirection::Descending,
        ));

        return array_map(static fn (Issue $issue): string => $issue->getTitle(), $issues->items);
    }
}
