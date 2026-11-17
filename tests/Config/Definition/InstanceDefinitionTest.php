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
        self::assertSame(['new', 'in_progress', 'to_test', 'done'], $definition->workflow('bug')->places);
        self::assertSame(['new', 'in_progress', 'to_test', 'done'], array_keys($definition->places));
        self::assertSame('Closed', $definition->place('done')->label);
        self::assertSame(['project_manager'], $definition->rolesGranted(Permission::IssueSetPrivate));
        self::assertSame(['project_manager', 'developer'], $definition->rolesGranted(Permission::IssueAssign));
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

        new RoleDefinition(id: 'Project manager', label: 'Project manager');
    }

    public function testLabelsCannotBeBlank(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('places.new.label: a label is required.');

        new PlaceDefinition(id: 'new', label: '  ', status: PlaceStatus::Open);
    }

    public function testWorkflowNavigation(): void
    {
        $workflow = DefinitionFactory::bugWorkflow();

        self::assertSame(['deliver'], array_map(static fn ($t) => $t->id, $workflow->transitionsFrom('in_progress')));
        self::assertSame(['environment', 'url'], array_keys($workflow->fields));
        self::assertTrue($workflow->fields['environment']->required);
    }

    public function testTransitionNeedsSourcesAndRoles(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('at least one role');

        new TransitionDefinition(id: 'start', label: 'Start', from: ['new'], to: 'done', roles: []);
    }

    public function testCommentPromptRequiresAComment(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        new TransitionDefinition(id: 'refuse', label: 'Refuse', from: ['new'], to: 'done', roles: ['client'], commentPrompt: 'Why?');
    }

    public function testUnknownPlaceLookupThrows(): void
    {
        $definition = DefinitionFactory::definition();

        self::assertNull($definition->findPlace('b'));
        $this->expectException(InvalidDefinitionException::class);
        $definition->place('b');
    }

    public function testThePlacesOfAWorkflowFollowItsOrderThoseOfAListTheInstance(): void
    {
        $reversed = new WorkflowDefinition(id: 'task', label: 'Task', initial: 'done', places: ['done', 'new'], transitions: []);
        $definition = DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(withToTest: false), $reversed], places: DefinitionFactory::places());

        $ids = static fn (array $places): array => array_map(static fn (PlaceDefinition $place): string => $place->id, $places);

        self::assertSame(['done', 'new'], $ids($definition->placesOf($reversed)));
        self::assertSame(['new', 'done'], $ids($definition->placesIn(['task', 'unknown'])));
        self::assertSame(['new', 'in_progress', 'done'], $ids($definition->placesIn(['task', 'bug'])), 'a place two workflows share is offered once');
    }
}
