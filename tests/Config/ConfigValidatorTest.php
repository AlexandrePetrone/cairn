<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigValidator;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\MigrationPolicy;
use App\Entity\Enum\PlaceStatus;
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

    public function testInitialAndFallbackMustBePlaces(): void
    {
        $workflow = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'nowhere',
            places: [new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open)],
            transitions: [],
            fallbackPlace: 'elsewhere',
        );

        self::assertSame([
            'workflows.w.initial: unknown place "nowhere".',
            'workflows.w.fallbackPlace: unknown place "elsewhere".',
        ], $this->messages(DefinitionFactory::definition(workflows: [$workflow])));
    }

    public function testFallbackPolicyRequiresAFallbackPlace(): void
    {
        $workflow = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: [new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open)],
            transitions: [],
        );
        $definition = DefinitionFactory::definition(workflows: [$workflow], migrationPolicy: MigrationPolicy::Fallback);

        self::assertSame(['workflows.w.fallbackPlace: required under the Fallback migration policy.'], $this->messages($definition));
    }

    public function testTransitionsMustReferenceKnownPlacesAndRoles(): void
    {
        $workflow = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: [new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open)],
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
            places: [
                new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'b', label: 'B', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'close', label: 'Fermer', from: ['a'], to: 'b', roles: ['developer']),
                new TransitionDefinition(id: 'reject', label: 'Rejeter', from: ['a'], to: 'b', roles: ['developer']),
            ],
        );

        self::assertSame(
            ['workflows.w.transitions.reject: transition "close" already goes from "a" to "b"; the kanban needs a single transition per pair of places.'],
            $this->messages(DefinitionFactory::definition(workflows: [$workflow])),
        );
    }

    public function testMigrateFromCannotNameAnExistingPlaceNorTheSamePlaceTwice(): void
    {
        $workflow = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: [
                new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open, migrateFrom: ['old', 'b']),
                new PlaceDefinition(id: 'b', label: 'B', status: PlaceStatus::Closed, migrateFrom: ['old']),
            ],
            transitions: [],
        );

        self::assertSame([
            'workflows.w.places.a.migrateFrom: "b" is still a place of this workflow.',
            'workflows.w.places.b.migrateFrom: "old" is already migrated to "a".',
        ], $this->messages(DefinitionFactory::definition(workflows: [$workflow])));
    }

    public function testWorkflowFieldsMustExist(): void
    {
        $workflow = new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: [new PlaceDefinition(id: 'a', label: 'A', status: PlaceStatus::Open)],
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
