<?php

declare(strict_types=1);

namespace App\Tests\Config\Definition;

use App\Config\Definition\InvalidDefinitionException;
use App\Config\Definition\WorkflowDefinition;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionTest extends TestCase
{
    private WorkflowDefinition $workflow;

    protected function setUp(): void
    {
        $this->workflow = new WorkflowDefinition(
            id: 'bug',
            label: 'Bug',
            initial: 'new',
            places: ['triage', 'new', 'waiting', 'in_progress', 'blocked', 'done', 'cancelled'],
            transitions: [],
            aside: ['waiting' => 'new', 'blocked' => 'in_progress', 'cancelled' => 'new'],
        );
    }

    public function testThePathIsThePlacesInTheirOrderWithoutThoseBesideIt(): void
    {
        self::assertSame(['triage', 'new', 'in_progress', 'done'], $this->workflow->path());
    }

    public function testTheTrailToAPlaceFollowsThePathFromTheInitialPlace(): void
    {
        self::assertSame([], $this->workflow->trailTo('new'));
        self::assertSame(['in_progress', 'done'], $this->workflow->trailTo('done'));
        self::assertSame(['triage'], $this->workflow->trailTo('triage'), 'a place the kanban sets before the initial one is reached at once');
    }

    public function testTheTrailToAPlaceBesideThePathGoesThroughThePlaceItStandsBeside(): void
    {
        self::assertSame(['waiting'], $this->workflow->trailTo('waiting'));
        self::assertSame(['in_progress', 'blocked'], $this->workflow->trailTo('blocked'));
        self::assertSame(['cancelled'], $this->workflow->trailTo('cancelled'), 'closed or not');
    }

    public function testThereIsNoTrailToAPlaceTheWorkflowDoesNotHave(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->workflow->trailTo('nowhere');
    }
}
