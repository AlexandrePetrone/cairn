<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Workflow\MermaidDiagram;
use PHPUnit\Framework\TestCase;

/**
 * A bug goes new → in_progress → to_test → done; "waiting" and "rejected"
 * stand beside "new", "parked" beside "in_progress". The team moves freely and has no action; the
 * client and the partner have theirs.
 */
final class MermaidDiagramTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = self::render(new WorkflowDefinition(
            id: 'bug',
            label: 'Bug',
            initial: 'new',
            places: ['new', 'waiting', 'in_progress', 'parked', 'to_test', 'done', 'rejected'],
            transitions: [
                new TransitionDefinition(id: 'answer', label: 'Answer', from: ['waiting'], to: 'new', roles: ['client']),
                new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'done', roles: ['client', 'partner']),
                new TransitionDefinition(id: 'refuse', label: 'Refuse', from: ['to_test'], to: 'in_progress', roles: ['client'], requiresComment: true),
                new TransitionDefinition(id: 'drop', label: 'Drop', from: ['new', 'to_test'], to: 'rejected', roles: ['partner']),
                new TransitionDefinition(id: 'give_up', label: 'Give up', from: ['done'], to: 'new', roles: []),
            ],
            aside: ['waiting' => 'new', 'rejected' => 'new', 'parked' => 'in_progress'],
        ));
    }

    public function testEachPlaceIsANodeColouredByItsStatus(): void
    {
        self::assertStringStartsWith("flowchart TB\n", $this->source);
        self::assertStringContainsString('start(( )):::start --> p_new', $this->source);
        self::assertStringContainsString('p_in_progress("In #quot;progress#quot; #35;1"):::in_progress', $this->source, 'labels are escaped');
        self::assertStringContainsString('p_done("Done"):::closed', $this->source);
        self::assertStringContainsString('classDef closed ', $this->source);
        self::assertStringContainsString('fill:var(--color-blue-soft)', $this->source);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{6}/i', $this->source, 'Colours are roles, so that the drawing follows the theme.');
    }

    public function testThePathLinksThePlacesInTheirOrderWithoutThoseBesideIt(): void
    {
        self::assertStringContainsString("  p_new --> p_in_progress\n", $this->source, '"waiting" comes between them in the kanban, beside the path');
        self::assertStringContainsString("  p_in_progress --> p_to_test\n", $this->source);
        self::assertStringNotContainsString('p_new --> p_waiting', $this->source);
        self::assertStringNotContainsString('p_done --> p_rejected', $this->source);
    }

    public function testAnActionThatLeadsToTheNextPlaceOfThePathIsItsArrow(): void
    {
        self::assertStringContainsString("  p_to_test -->|\"Accept\nClient, Partner\"| p_done\n", $this->source);
        self::assertSame(1, substr_count($this->source, 'p_to_test -->|"Accept'), 'drawn once: the arrow of the path is the action\'s');
        self::assertStringNotContainsString("p_to_test --> p_done\n", $this->source);
    }

    public function testAnActionSaysItsRolesItsCommentAndWhetherItGoesBack(): void
    {
        self::assertStringContainsString("  p_to_test -.->|\"Refuse 💬\nClient\"| p_in_progress\n", $this->source, 'back in the kanban order, comment required');
        self::assertStringContainsString("  p_waiting -.->|\"Answer\nClient\"| p_new\n", $this->source);
        self::assertStringContainsString("  p_new -->|\"Drop\nPartner\"| p_rejected\n", $this->source);
        self::assertStringContainsString("  p_to_test -->|\"Drop\nPartner\"| p_rejected\n", $this->source, 'an arrow from each place it starts from');
    }

    public function testAnActionNoRoleTriggersIsNotDrawn(): void
    {
        self::assertStringNotContainsString('Give up', $this->source);
        self::assertStringNotContainsString('p_done -', $this->source);
    }

    public function testAPlaceAnActionStartsFromIsOutlined(): void
    {
        self::assertStringContainsString('p_to_test("To test"):::waiting_acted', $this->source);
        self::assertStringContainsString('p_waiting("Waiting"):::waiting_acted', $this->source);
        self::assertStringContainsString("p_done(\"Done\"):::closed\n", $this->source, 'the action that starts from there is nobody\'s');
        self::assertStringContainsString('classDef waiting_acted ', $this->source);
    }

    public function testTheArrowsOfTheActionsAreToldFromThePath(): void
    {
        // In the order of the source: the start, three links of the path (the last one an action), then four actions.
        self::assertStringContainsString('  linkStyle 3,4,5,6,7 stroke:var(--color-strong)', $this->source);
    }

    public function testAPlaceBesideThePathHangsFromThePlaceItStandsBeside(): void
    {
        self::assertStringContainsString("  p_new -.-> p_waiting\n", $this->source, 'an action takes the issue out of it: the line only leads there');
        self::assertStringContainsString("  p_in_progress <-.-> p_parked\n", $this->source, 'no action leaves it: there and back');
        self::assertStringNotContainsString('p_new -.-> p_rejected', $this->source, '"Drop" already leads there from "new"');
        self::assertStringNotContainsString('p_new <-.-> p_rejected', $this->source);
        self::assertStringContainsString('linkStyle 3,4,5,6,7 ', $this->source, 'these lines come last and keep the colour of the path');
    }

    public function testAWorkflowWithoutActionIsItsPath(): void
    {
        $source = self::render(new WorkflowDefinition(id: 'bug', label: 'Task', initial: 'new', places: ['new', 'in_progress', 'done'], transitions: []));

        self::assertStringContainsString("  p_new --> p_in_progress\n  p_in_progress --> p_done\n", $source);
        self::assertStringNotContainsString('linkStyle', $source);
        self::assertStringNotContainsString(':::open_acted', $source);
    }

    public function testAPlaceWhoseKeyIsAWordOfMermaidHasItsOwnNode(): void
    {
        $source = self::render(new WorkflowDefinition(id: 'bug', label: 'Task', initial: 'start', places: ['start', 'end'], transitions: [
            new TransitionDefinition(id: 'finish', label: 'Finish', from: ['start'], to: 'end', roles: ['client']),
        ]));

        self::assertStringContainsString('start(( )):::start --> p_start', $source, 'the step is not the dot the diagram starts from');
        self::assertStringContainsString("p_start -->|\"Finish\nClient\"| p_end", $source);
        self::assertDoesNotMatchRegularExpression('/^\s*end\b/m', $source, '`end` alone closes a block for Mermaid');
    }

    public function testThePlaceOfAnIssueIsOutlined(): void
    {
        $workflow = new WorkflowDefinition(id: 'bug', label: 'Bug', initial: 'new', places: ['new', 'done'], transitions: []);

        self::assertStringNotContainsString('  style ', self::render($workflow), 'nothing is outlined in the administration');
        self::assertStringContainsString("  style p_done stroke:var(--color-brand-solid),stroke-width:4px\n", self::render($workflow, 'done'));
        self::assertStringNotContainsString('  style ', self::render($workflow, 'elsewhere'), 'a place that left the workflow is not one of its nodes');
    }

    private static function render(WorkflowDefinition $workflow, ?string $current = null): string
    {
        $labels = ['in_progress' => 'In "progress" #1'];
        $statuses = ['in_progress' => PlaceStatus::InProgress, 'to_test' => PlaceStatus::Waiting, 'waiting' => PlaceStatus::Waiting, 'done' => PlaceStatus::Closed, 'end' => PlaceStatus::Closed, 'rejected' => PlaceStatus::Closed];

        return (new MermaidDiagram())->render(new InstanceDefinition(
            roles: [new RoleDefinition('developer', 'Developer', freeWorkflow: true), new RoleDefinition('client', 'Client'), new RoleDefinition('partner', 'Partner')],
            permissions: [],
            fields: [],
            links: [],
            places: array_map(static fn (string $id): PlaceDefinition => new PlaceDefinition($id, $labels[$id] ?? ucfirst(str_replace('_', ' ', $id)), $statuses[$id] ?? PlaceStatus::Open), $workflow->places),
            workflows: [$workflow],
        ), $workflow, $current);
    }
}
