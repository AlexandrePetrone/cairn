<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\DiffCalculator;
use App\Config\FieldType;
use App\Config\MigrationPolicy;
use App\Config\SnapshotCodec;
use App\Entity\Enum\PlaceStatus;
use App\Tests\Config\Fixture\DefinitionFactory;
use PHPUnit\Framework\TestCase;

final class DiffCalculatorTest extends TestCase
{
    private DiffCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DiffCalculator(new SnapshotCodec());
    }

    public function testFirstSyncAddsEverythingItemByItem(): void
    {
        self::assertSame([
            '+ roles.project_manager: "Project manager"',
            '+ roles.developer: "Developer"',
            '+ roles.client: "Client"',
            '+ fields.url: "URL"',
            '+ fields.environment: "Environment"',
            '+ links.related: "Related to"',
            '+ links.blocks: "Blocks"',
            '+ workflows.bug: "Bug"',
            '+ migrationPolicy: strict',
            '+ permissions.comment.create: [project_manager, developer, client]',
            '+ permissions.comment.edit: [project_manager]',
            '+ permissions.comment.set_private: [project_manager, developer]',
            '+ permissions.issue.assign: [project_manager, developer]',
            '+ permissions.issue.change_tracker: [project_manager, developer]',
            '+ permissions.issue.create: [project_manager, developer, client]',
            '+ permissions.issue.edit: [project_manager]',
            '+ permissions.issue.force_place: [project_manager]',
            '+ permissions.issue.link: [project_manager, developer]',
            '+ permissions.issue.rank: [project_manager, developer]',
            '+ permissions.issue.set_criticality: [project_manager, developer, client]',
            '+ permissions.issue.set_private: [project_manager]',
            '+ permissions.issue.set_release: [project_manager, developer]',
            '+ permissions.project.configure: [project_manager]',
            '+ permissions.project.manage_members: [project_manager]',
            '+ permissions.release.manage: [project_manager]',
        ], $this->calculator->between(null, DefinitionFactory::definition()));
    }

    public function testIdenticalDefinitionsHaveAnEmptyDiff(): void
    {
        self::assertSame([], $this->calculator->between(DefinitionFactory::definition(), DefinitionFactory::definition()));
    }

    public function testScalarChangesAreReportedByPath(): void
    {
        $diff = $this->calculator->between(
            DefinitionFactory::definition(),
            DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(label: 'Defect')], migrationPolicy: MigrationPolicy::Fallback),
        );

        self::assertSame([
            '~ workflows.bug.label: Bug → Defect',
            '~ migrationPolicy: strict → fallback',
        ], $diff);
    }

    public function testRemovedAndAddedItemsAreMatchedById(): void
    {
        $diff = $this->calculator->between(
            DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: true)]),
            DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false)]),
        );

        self::assertSame([
            '- workflows.bug.places.to_test: "To test"',
            '+ workflows.bug.transitions.finish: "Finish"',
            '- workflows.bug.transitions.deliver: "Deliver"',
            '- workflows.bug.transitions.accept: "Accept"',
            '- workflows.bug.transitions.refuse: "Refuse"',
        ], $diff);
    }

    public function testChangesInsideAnItemAreReportedKeyByKey(): void
    {
        $before = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: [
                new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'b', label: 'B', status: PlaceStatus::Waiting),
            ],
            transitions: [new TransitionDefinition(id: 't', label: 'T', from: ['a'], to: 'b', roles: ['developer'])],
        );
        $after = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: [
                new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'b', label: 'Done', status: PlaceStatus::Closed),
            ],
            transitions: [new TransitionDefinition(id: 't', label: 'T', from: ['a'], to: 'b', roles: ['developer', 'client'], requiresComment: true)],
        );

        $diff = $this->calculator->between(
            DefinitionFactory::definition(workflows: [$before]),
            DefinitionFactory::definition(workflows: [$after]),
        );

        self::assertSame([
            '~ workflows.w.places.b.label: B → Done',
            '~ workflows.w.places.b.status: waiting → closed',
            '~ workflows.w.transitions.t.roles: [developer] → [developer, client]',
            '~ workflows.w.transitions.t.requiresComment: no → yes',
        ], $diff);
    }

    public function testMapsAndRemovedWorkflows(): void
    {
        $before = DefinitionFactory::definition(
            workflows: [DefinitionFactory::bugWorkflow(), new WorkflowDefinition(id: 'task', label: 'Task', initial: 'todo', places: [new PlaceDefinition(id: 'todo', label: 'To do', status: PlaceStatus::Open)], transitions: [])],
            permissions: ['issue.create' => ['client']],
        );
        $after = DefinitionFactory::definition(
            fields: [...DefinitionFactory::fields(), new FieldDefinition(id: 'browser', label: 'Browser', type: FieldType::Text)],
            permissions: ['issue.create' => ['client', 'developer'], 'issue.assign' => ['developer']],
        );

        $diff = $this->calculator->between($before, $after);

        self::assertSame([
            '+ fields.browser: "Browser"',
            '- workflows.task: "Task"',
            '+ permissions.issue.assign: [developer]',
            '~ permissions.issue.create: [client] → [client, developer]',
        ], $diff);
    }
}
