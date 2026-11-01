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
            '+ roles.project_manager: «Chef de projet»',
            '+ roles.developer: «Développeur»',
            '+ roles.client: «Client»',
            '+ fields.url: «URL»',
            '+ fields.environment: «Environnement»',
            '+ links.related: «Lié à»',
            '+ links.blocks: «Bloque»',
            '+ workflows.bug: «Anomalie»',
            '+ migrationPolicy: strict',
            '+ permissions.comment.create: [project_manager, developer, client]',
            '+ permissions.comment.edit: [project_manager]',
            '+ permissions.comment.set_private: [project_manager, developer]',
            '+ permissions.issue.assign: [project_manager, developer]',
            '+ permissions.issue.change_tracker: [project_manager, developer]',
            '+ permissions.issue.create: [project_manager, developer, client]',
            '+ permissions.issue.edit: [project_manager]',
            '+ permissions.issue.set_criticality: [project_manager, developer, client]',
            '+ permissions.issue.set_private: [project_manager]',
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
            DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(label: 'Bug')], migrationPolicy: MigrationPolicy::Fallback),
        );

        self::assertSame([
            '~ workflows.bug.label: Anomalie → Bug',
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
            '- workflows.bug.places.to_test: «À recetter»',
            '+ workflows.bug.transitions.finish: «Terminer»',
            '- workflows.bug.transitions.deliver: «Livrer»',
            '- workflows.bug.transitions.accept: «Valider»',
            '- workflows.bug.transitions.refuse: «Refuser»',
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
                new PlaceDefinition(id: 'b', label: 'B', status: PlaceStatus::Closed, hint: 'Done.'),
            ],
            transitions: [new TransitionDefinition(id: 't', label: 'T', from: ['a'], to: 'b', roles: ['developer', 'client'], requiresComment: true)],
        );

        $diff = $this->calculator->between(
            DefinitionFactory::definition(workflows: [$before]),
            DefinitionFactory::definition(workflows: [$after]),
        );

        self::assertSame([
            '~ workflows.w.places.b.status: waiting → closed',
            '~ workflows.w.places.b.hint: none → Done.',
            '~ workflows.w.transitions.t.roles: [developer] → [developer, client]',
            '~ workflows.w.transitions.t.requiresComment: no → yes',
        ], $diff);
    }

    public function testMapsAndRemovedWorkflows(): void
    {
        $before = DefinitionFactory::definition(
            workflows: [DefinitionFactory::bugWorkflow(), new WorkflowDefinition(id: 'task', label: 'Tâche', initial: 'todo', places: [new PlaceDefinition(id: 'todo', label: 'À faire', status: PlaceStatus::Open)], transitions: [])],
            permissions: ['issue.create' => ['client']],
        );
        $after = DefinitionFactory::definition(
            fields: [...DefinitionFactory::fields(), new FieldDefinition(id: 'browser', label: 'Navigateur', type: FieldType::Text)],
            permissions: ['issue.create' => ['client', 'developer'], 'issue.assign' => ['developer']],
        );

        $diff = $this->calculator->between($before, $after);

        self::assertSame([
            '+ fields.browser: «Navigateur»',
            '- workflows.task: «Tâche»',
            '+ permissions.issue.assign: [developer]',
            '~ permissions.issue.create: [client] → [client, developer]',
        ], $diff);
    }
}
