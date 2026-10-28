<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\Definition\PlaceDefinition;
use App\Config\Sync\DoctrineIssuePlaceGateway;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Tracker;
use App\Message\Command\Issue\ApplyTransition;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineIssuePlaceGatewayTest extends KernelTestCase
{
    use KernelHelpers;

    private DoctrineIssuePlaceGateway $gateway;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->gateway = self::getContainer()->get(DoctrineIssuePlaceGateway::class);
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
        $otherTracker = new Tracker($this->fixtures->access->otherProject, 'Autre', 'other_workflow');
        $this->entityManager->persist($otherTracker);
        $other = new Issue($this->fixtures->access->otherProject, $otherTracker, 1, $this->fixtures->client, 'Autre', '');
        $other->moveTo(new PlaceDefinition(id: 'new', label: 'Nouveau', status: PlaceStatus::Open));
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        self::assertSame(2, $this->gateway->countByPlace('bug', 'new'));
        self::assertSame(1, $this->gateway->countByPlace('bug', 'in_progress'));
        self::assertSame(0, $this->gateway->countByPlace('bug', 'done'));

        $moved = $this->gateway->movePlace('bug', 'new', new PlaceDefinition(id: 'done', label: 'Fermée', status: PlaceStatus::Closed));

        self::assertSame(2, $moved);
        self::assertSame(0, $this->gateway->countByPlace('bug', 'new'));
        self::assertSame(2, $this->gateway->countByPlace('bug', 'done'));
        self::assertSame(1, $this->gateway->countByPlace('other_workflow', 'new'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Issue::class, $a->getId());
        self::assertInstanceOf(Issue::class, $reloaded);
        self::assertSame('done', $reloaded->getPlace());
        self::assertSame(PlaceStatus::Closed, $reloaded->getStatus());
        self::assertNotNull($reloaded->getClosedAt(), 'closed by the migration');
        self::assertSame($a->getUpdatedAt()->format('Y-m-d H:i:s'), $reloaded->getUpdatedAt()->format('Y-m-d H:i:s'), 'a migration is not an activity');

        $this->gateway->movePlace('bug', 'done', new PlaceDefinition(id: 'new', label: 'Nouveau', status: PlaceStatus::Open));
        $this->entityManager->clear();
        $reopened = $this->entityManager->find(Issue::class, $b->getId());
        self::assertInstanceOf(Issue::class, $reopened);
        self::assertNull($reopened->getClosedAt(), 'reopened by the migration');
    }
}
