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
            label: 'Anomalie',
            initial: 'new',
            places: [
                new PlaceDefinition(id: 'new', label: 'Nouveau', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'in_progress', label: 'En "cours" #1', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'to_test', label: 'À recetter', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'rejected', label: 'Rejetée', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'start', label: 'Démarrer', from: ['new'], to: 'in_progress', roles: ['developer']),
                new TransitionDefinition(id: 'deliver', label: 'Livrer', from: ['in_progress'], to: 'to_test', roles: ['developer']),
                new TransitionDefinition(id: 'refuse', label: 'Refuser', from: ['to_test'], to: 'in_progress', roles: ['client'], requiresComment: true),
                new TransitionDefinition(id: 'reject', label: 'Rejeter', from: ['new', 'in_progress'], to: 'rejected', roles: ['developer']),
            ],
        );
        $this->source = (new MermaidDiagram())->render($this->workflow);
    }

    public function testEachPlaceIsANodeColouredByItsStatus(): void
    {
        self::assertStringStartsWith("flowchart LR\n", $this->source);
        self::assertStringContainsString('start(( )):::start --> new', $this->source);
        self::assertStringContainsString('new(["Nouveau"]):::open', $this->source);
        self::assertStringContainsString('to_test(["À recetter"]):::waiting', $this->source);
        self::assertStringContainsString('classDef closed ', $this->source);
    }

    public function testLabelsAreEscaped(): void
    {
        self::assertStringContainsString('in_progress(["En #quot;cours#quot; #35;1"]):::in_progress', $this->source);
    }

    public function testForwardArrowsAreSolidAndReturnsDotted(): void
    {
        self::assertStringContainsString('new -->|"Démarrer"| in_progress', $this->source);
        self::assertStringContainsString('to_test -.->|"Refuser 💬"| in_progress', $this->source, 'back in the kanban order, comment required');
    }

    public function testATransitionFromSeveralPlacesHasAnArrowFromEach(): void
    {
        self::assertStringContainsString('new -->|"Rejeter"| rejected', $this->source);
        self::assertStringContainsString('in_progress -->|"Rejeter"| rejected', $this->source);
    }
}
