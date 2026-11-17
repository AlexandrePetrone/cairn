<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Tracker;
use App\Message\Command\Config\CreateWorkflow;
use App\Message\Command\Issue\ApplyTransition;
use App\Repository\IssueRepository;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bulk operations the configuration sync relies on when a place is
 * removed from a workflow.
 */
final class IssueRepositoryTest extends KernelTestCase
{
    use KernelHelpers;

    private IssueRepository $issues;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->issues = self::getContainer()->get(IssueRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, self::commandBus());
    }

    public function testCountsAndMovesTheIssuesOfOneWorkflowPlace(): void
    {
        $commands = self::commandBus();
        $developer = (int) $this->fixtures->developer->getId();
        $a = $this->fixtures->createIssue($this->fixtures->client, title: 'A');
        $b = $this->fixtures->createIssue($this->fixtures->client, title: 'B');
        $c = $this->fixtures->createIssue($this->fixtures->client, title: 'C');
        $commands->dispatch(new ApplyTransition($developer, $c->getKey(), 'start'));

        // An issue of another workflow on a place with the same id must not move.
        $commands->dispatch(new CreateWorkflow('Other', 'new', 'other_workflow'));
        $otherTracker = new Tracker($this->fixtures->access->otherProject, 'Other', 'other_workflow');
        $this->entityManager->persist($otherTracker);
        $other = new Issue($this->fixtures->access->otherProject, $otherTracker, DefinitionFactory::place('new'), 1, $this->fixtures->client, 'Other', '');
        $other->moveTo(new PlaceDefinition(id: 'new', label: 'New', status: PlaceStatus::Open));
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        self::assertSame([
            ['workflowId' => 'bug', 'placeId' => 'in_progress', 'issues' => 1],
            ['workflowId' => 'bug', 'placeId' => 'new', 'issues' => 2],
            ['workflowId' => 'other_workflow', 'placeId' => 'new', 'issues' => 1],
        ], $this->issues->countByPlace(), 'only the places in use, by workflow then place');

        $moved = $this->issues->moveFromPlace('bug', 'new', 'done', PlaceStatus::Closed, new \DateTimeImmutable('2026-03-03 10:00:00'));

        self::assertSame(2, $moved);
        self::assertSame([
            ['workflowId' => 'bug', 'placeId' => 'done', 'issues' => 2],
            ['workflowId' => 'bug', 'placeId' => 'in_progress', 'issues' => 1],
            ['workflowId' => 'other_workflow', 'placeId' => 'new', 'issues' => 1],
        ], $this->issues->countByPlace());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Issue::class, $a->getId());
        self::assertInstanceOf(Issue::class, $reloaded);
        self::assertSame('done', $reloaded->getPlace());
        self::assertSame(PlaceStatus::Closed, $reloaded->getStatus());
        self::assertSame('2026-03-03 10:00:00', $reloaded->getClosedAt()?->format('Y-m-d H:i:s'), 'closed by the move');
        self::assertSame($a->getUpdatedAt()->format('Y-m-d H:i:s'), $reloaded->getUpdatedAt()->format('Y-m-d H:i:s'), 'a move is not an activity');

        $ranks = $this->entityManager->getRepository(Issue::class)->findBy(['tracker' => $this->fixtures->bugs, 'place' => 'done'], ['boardRank' => 'DESC']);
        self::assertSame(['B', 'A'], array_map(static fn (Issue $issue): string => $issue->getTitle(), $ranks), 'the column that took them keeps their order');

        $this->issues->moveFromPlace('bug', 'done', 'new', PlaceStatus::Open, null);
        $this->entityManager->clear();
        $reopened = $this->entityManager->find(Issue::class, $b->getId());
        self::assertInstanceOf(Issue::class, $reopened);
        self::assertNull($reopened->getClosedAt(), 'reopened by the move');
    }

    public function testAColumnThatTakesTheIssuesOfARemovedPlaceHoldsNoTwoAtTheSameRank(): void
    {
        $commands = self::commandBus();
        $developer = (int) $this->fixtures->developer->getId();
        foreach (['A', 'B', 'C', 'D'] as $index => $title) {
            $issue = $this->fixtures->createIssue($this->fixtures->client, title: $title);
            if ($index < 2) {
                $commands->dispatch(new ApplyTransition($developer, $issue->getKey(), 'start'));
            }
        }

        // Both columns ranked their two issues 0 and one step.
        $this->issues->moveFromPlace('bug', 'new', 'in_progress', PlaceStatus::InProgress, null);

        $this->entityManager->clear();
        $ranks = array_map(static fn (Issue $issue): int => $issue->getBoardRank(), $this->entityManager->getRepository(Issue::class)->findBy(['place' => 'in_progress']));
        self::assertCount(4, $ranks);
        self::assertCount(4, array_unique($ranks));
    }
}
