<?php

declare(strict_types=1);

namespace App\Tests\Config\Definition;

use App\Config\Definition\InvalidDefinitionException;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Security\Permission;
use App\Tests\Config\Fixture\DefinitionFactory;
use PHPUnit\Framework\TestCase;

final class InstanceDefinitionTest extends TestCase
{
    public function testCollectionsAreIndexedByIdInDeclarationOrder(): void
    {
        $definition = DefinitionFactory::definition();

        self::assertSame(['project_manager', 'developer', 'client'], $definition->roleIds());
        self::assertSame(0, $definition->roleRank('project_manager'));
        self::assertSame(2, $definition->roleRank('client'));
        self::assertSame(['new', 'in_progress', 'to_test', 'done'], $definition->workflow('bug')->placeIds());
        self::assertSame(['project_manager'], $definition->rolesGranted(Permission::TrackerManage));
        self::assertSame([], $definition->rolesGranted(Permission::WorkspaceManage));
    }

    public function testDuplicateIdentifiersAreRejected(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('roles: duplicate identifier "client"');

        DefinitionFactory::definition(roles: [
            new RoleDefinition(id: 'client', label: 'Client'),
            new RoleDefinition(id: 'client', label: 'Client bis'),
        ]);
    }

    public function testInvalidIdentifiersAreRejected(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        new RoleDefinition(id: 'Chef de projet', label: 'Chef de projet');
    }

    public function testLabelsCannotBeBlank(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('places.new.label: a label is required.');

        new PlaceDefinition(id: 'new', label: '  ', status: PlaceStatus::Open);
    }

    public function testLinkInverseLabelFallsBackToLabelWhenSymmetric(): void
    {
        $definition = DefinitionFactory::definition();

        self::assertTrue($definition->link('related')->isSymmetric());
        self::assertNull($definition->link('related')->inverseLabel);
        self::assertSame('Lié à', $definition->link('related')->labelFromTarget());
        self::assertFalse($definition->link('blocks')->isSymmetric());
        self::assertSame('Bloqué par', $definition->link('blocks')->labelFromTarget());
    }

    public function testWorkflowNavigation(): void
    {
        $workflow = DefinitionFactory::bugWorkflow();

        self::assertSame(['deliver'], array_map(static fn ($t) => $t->id, $workflow->transitionsFrom('in_progress')));
        self::assertSame(['done', 'in_progress'], $workflow->reachableFrom('to_test'));
        self::assertSame('refuse', $workflow->transitionBetween('to_test', 'in_progress')?->id);
        self::assertNull($workflow->transitionBetween('new', 'done'));
        self::assertSame(['environment', 'url'], array_keys($workflow->fields));
        self::assertTrue($workflow->fields['environment']->required);
        self::assertSame('in_progress', DefinitionFactory::bugWorkflow(withToTest: false, migrateInProgressFrom: ['to_test'])->migrationTargetFor('to_test'));
        self::assertNull($workflow->migrationTargetFor('to_test'));
    }

    public function testTransitionNeedsSourcesAndRoles(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('at least one role');

        new TransitionDefinition(id: 'start', label: 'Démarrer', from: ['new'], to: 'done', roles: []);
    }

    public function testCommentPromptRequiresAComment(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        new TransitionDefinition(id: 'refuse', label: 'Refuser', from: ['new'], to: 'done', roles: ['client'], commentPrompt: 'Pourquoi ?');
    }

    public function testGlobalRoleMustBeInternal(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        new RoleDefinition(id: 'everyone', label: 'Tout le monde', global: true);
    }

    public function testUnknownPlaceLookupThrows(): void
    {
        $workflow = new WorkflowDefinition(id: 'w', label: 'W', initial: 'a', places: [new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open)], transitions: []);

        $this->expectException(InvalidDefinitionException::class);
        $workflow->place('b');
    }
}
