<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigValidator;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Tests\Config\Fixture\DefinitionFactory;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
{
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
    }

    public function testAValidDefinitionHasNoViolation(): void
    {
        self::assertSame([], $this->validator->validate(DefinitionFactory::definition()));
    }

    public function testUnknownPermissionAndRoleInPermissions(): void
    {
        $definition = DefinitionFactory::definition(permissions: [
            'issue.create' => ['client', 'ghost'],
            'issue.fly' => ['client'],
        ]);

        self::assertSame([
            'permissions.issue.create: unknown role "ghost".',
            'permissions.issue.fly: unknown permission.',
        ], $this->messages($definition));
    }

    public function testProjectPermissionsGoToInternalOnlyRoles(): void
    {
        $definition = DefinitionFactory::definition(permissions: [
            'project.configure' => ['project_manager', 'client'],
            'project.manage_members' => ['client'],
        ]);

        self::assertSame([
            'permissions.project.configure: role "client" is not internal-only; this permission opens what external users never see.',
            'permissions.project.manage_members: role "client" is not internal-only; this permission opens what external users never see.',
        ], $this->messages($definition));
    }

    public function testTheInitialPlaceMustBeAPlaceOfTheWorkflow(): void
    {
        $workflow = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'nowhere',
            places: ['a'],
            transitions: [],
        );

        self::assertSame([
            'workflows.w.initial: unknown place "nowhere".',
        ], $this->messages(DefinitionFactory::definition(workflows: [$workflow])));
    }

    public function testTransitionsMustReferenceKnownPlacesAndRoles(): void
    {
        $workflow = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: ['a'],
            transitions: [new TransitionDefinition(id: 't', label: 'T', from: ['a', 'x'], to: 'y', roles: ['developer', 'ghost'])],
        );

        self::assertSame([
            'workflows.w.transitions.t.from: unknown place "x".',
            'workflows.w.transitions.t.to: unknown place "y".',
            'workflows.w.transitions.t.roles: unknown role "ghost".',
        ], $this->messages(DefinitionFactory::definition(workflows: [$workflow])));
    }

    public function testOnlyOneTransitionPerPairOfPlaces(): void
    {
        $workflow = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: ['a', 'b'],
            transitions: [
                new TransitionDefinition(id: 'close', label: 'Close', from: ['a'], to: 'b', roles: ['developer']),
                new TransitionDefinition(id: 'reject', label: 'Reject', from: ['a'], to: 'b', roles: ['developer']),
            ],
        );

        self::assertSame(
            ['workflows.w.transitions.reject: transition "close" already goes from "a" to "b"; the kanban needs a single transition per pair of places.'],
            $this->messages(DefinitionFactory::definition(workflows: [$workflow])),
        );
    }

    public function testAWorkflowNamesPlacesOfTheInstance(): void
    {
        $workflow = new WorkflowDefinition(id: 'w', label: 'W', initial: 'new', places: ['new', 'ghost'], transitions: []);

        self::assertSame(
            ['workflows.w.places: unknown place "ghost".'],
            $this->messages(DefinitionFactory::definition(workflows: [$workflow], places: DefinitionFactory::places())),
        );
    }

    public function testWorkflowFieldsMustExist(): void
    {
        $workflow = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: ['a'],
            transitions: [],
            fields: [new FieldUsage('url'), new FieldUsage('ghost')],
        );

        self::assertSame(['workflows.w.fields: unknown field "ghost".'], $this->messages(DefinitionFactory::definition(workflows: [$workflow])));
    }

    /**
     * @return list<string>
     */
    private function messages(InstanceDefinition $definition): array
    {
        return array_map(strval(...), $this->validator->validate($definition));
    }
}
