<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Workflow\MermaidDiagram;
use PHPUnit\Framework\TestCase;

final class MermaidDiagramTest extends TestCase
{
    private string $source;
    private WorkflowDefinition $workflow;

    protected function setUp(): void
    {
        $this->workflow = new WorkflowDefinition(
            id: 'bug',
            label: 'Bug',
            initial: 'new',
            places: ['new', 'in_progress', 'to_test', 'rejected'],
            transitions: [
                new TransitionDefinition(id: 'start', label: 'Start', from: ['new'], to: 'in_progress', roles: ['developer']),
                new TransitionDefinition(id: 'deliver', label: 'Deliver', from: ['in_progress'], to: 'to_test', roles: ['developer']),
                new TransitionDefinition(id: 'refuse', label: 'Refuse', from: ['to_test'], to: 'in_progress', roles: ['client'], requiresComment: true),
                new TransitionDefinition(id: 'reject', label: 'Reject', from: ['new', 'in_progress'], to: 'rejected', roles: ['developer']),
            ],
        );
        $this->source = (new MermaidDiagram())->render($this->workflow, [
            new PlaceDefinition(id: 'new', label: 'New', status: PlaceStatus::Open),
            new PlaceDefinition(id: 'in_progress', label: 'In "progress" #1', status: PlaceStatus::InProgress),
            new PlaceDefinition(id: 'to_test', label: 'To test', status: PlaceStatus::Waiting),
            new PlaceDefinition(id: 'rejected', label: 'Rejected', status: PlaceStatus::Closed),
        ]);
    }

    public function testEachPlaceIsANodeColouredByItsStatus(): void
    {
        self::assertStringStartsWith("flowchart TB\n", $this->source);
        self::assertStringContainsString('start(( )):::start --> p_new', $this->source);
        self::assertStringContainsString('p_new("New"):::open', $this->source);
        self::assertStringContainsString('p_to_test("To test"):::waiting', $this->source);
        self::assertStringContainsString('classDef closed ', $this->source);
        self::assertStringContainsString('fill:var(--color-blue-soft)', $this->source);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{6}/i', $this->source, 'Colours are roles, so that the drawing follows the theme.');
    }

    public function testAPlaceWhoseKeyIsAWordOfMermaidHasItsOwnNode(): void
    {
        $source = (new MermaidDiagram())->render(
            new WorkflowDefinition(id: 'task', label: 'Task', initial: 'start', places: ['start', 'end'], transitions: [
                new TransitionDefinition(id: 'finish', label: 'Finish', from: ['start'], to: 'end', roles: ['developer']),
            ]),
            [new PlaceDefinition(id: 'start', label: 'Start', status: PlaceStatus::Open), new PlaceDefinition(id: 'end', label: 'End', status: PlaceStatus::Closed)],
        );

        self::assertStringContainsString('start(( )):::start --> p_start', $source, 'the step is not the dot the diagram starts from');
        self::assertStringContainsString('p_start -->|"Finish"| p_end', $source);
        self::assertDoesNotMatchRegularExpression('/^\s*end\b/m', $source, '`end` alone closes a block for Mermaid');
    }

    public function testLabelsAreEscaped(): void
    {
        self::assertStringContainsString('p_in_progress("In #quot;progress#quot; #35;1"):::in_progress', $this->source);
    }

    public function testForwardArrowsAreSolidAndReturnsDotted(): void
    {
        self::assertStringContainsString('p_new -->|"Start"| p_in_progress', $this->source);
        self::assertStringContainsString('p_to_test -.->|"Refuse 💬"| p_in_progress', $this->source, 'back in the kanban order, comment required');
    }

    public function testATransitionFromSeveralPlacesHasAnArrowFromEach(): void
    {
        self::assertStringContainsString('p_new -->|"Reject"| p_rejected', $this->source);
        self::assertStringContainsString('p_in_progress -->|"Reject"| p_rejected', $this->source);
    }
}
