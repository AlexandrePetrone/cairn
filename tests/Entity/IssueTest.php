<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Tests\Config\Fixture\DefinitionFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class IssueTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testTheKeyCombinesTheProjectPrefixAndTheNumber(): void
    {
        $issue = $this->issue();

        self::assertSame('MD-SPT-128', $issue->getKey());
        self::assertSame(128, $issue->getNumber());
        self::assertSame('new', $issue->getPlace(), 'born on the initial place');
        self::assertNull($issue->getClosedAt());
    }

    public function testMovingToAPlaceSetsTheClosingDate(): void
    {
        $workflow = DefinitionFactory::bugWorkflow();
        $issue = $this->issue();

        self::mockTime('2026-03-03 10:00:00');
        $issue->moveTo(DefinitionFactory::placeRow('new'));
        self::assertSame('new', $issue->getPlace());
        self::assertNull($issue->getClosedAt());
        self::assertSame('2026-03-03 10:00:00', $issue->getUpdatedAt()->format('Y-m-d H:i:s'));

        self::mockTime('2026-03-04 09:00:00');
        $issue->moveTo(DefinitionFactory::placeRow('done'));
        self::assertSame('2026-03-04 09:00:00', $issue->getClosedAt()?->format('Y-m-d H:i:s'));

        self::mockTime('2026-03-05 09:00:00');
        $issue->moveTo(DefinitionFactory::placeRow('in_progress'));
        self::assertNull($issue->getClosedAt(), 'reopening clears the closing date');
        self::assertSame('2026-03-05 09:00:00', $issue->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    public function testAnIssueOfAPrivateTrackerIsPrivate(): void
    {
        $issue = $this->issue();
        self::assertFalse($issue->isPrivate());

        $issue->getTracker()->makePrivate();
        self::assertTrue($issue->isPrivate());

        $issue->getTracker()->makePublic();
        $issue->makePrivate();
        self::assertTrue($issue->isPrivate());
    }

    public function testChangingTrackerKeepsFieldValues(): void
    {
        $issue = $this->issue();
        $issue->setFieldValues(['environment' => 'prod']);
        $other = new Tracker($issue->getProject(), 'Task', DefinitionFactory::workflowRow('bug'));

        $issue->changeTracker($other, DefinitionFactory::placeRow('in_progress'));

        self::assertSame($other, $issue->getTracker());
        self::assertSame('in_progress', $issue->getPlace());
        self::assertSame(['environment' => 'prod'], $issue->getFieldValues());
    }

    private function issue(): Issue
    {
        $project = new Project(new Workspace('Maison Dupont', 'MD'), 'Online shop', 'SPT');

        return new Issue($project, new Tracker($project, 'Bug', DefinitionFactory::workflowRow('bug')), DefinitionFactory::placeRow('new'), 128, new User('claire@client.test', 'Claire', 'Martin'), ' Empty CSV export ', 'The file is empty.');
    }
}
