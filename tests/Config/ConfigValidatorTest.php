<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigValidator;
use App\Config\Definition\FieldDefinition;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\FieldType;
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

    public function testEveryElementHasAWellFormedKeyAndALabel(): void
    {
        $workflow = new WorkflowDefinition(id: 'My workflow', label: '', initial: 'a', places: ['a'], transitions: [
            new TransitionDefinition(id: 'Go!', label: ' ', from: ['a'], to: 'a', roles: ['developer']),
        ]);
        $definition = DefinitionFactory::definition(
            workflows: [$workflow],
            roles: [new RoleDefinition(id: 'developer', label: 'Developer'), new RoleDefinition(id: 'Project manager', label: 'Project manager')],
            fields: [new FieldDefinition(id: 'url', label: '   ', type: FieldType::Url)],
            permissions: [],
            links: [new LinkTypeDefinition(id: '2nd', label: 'Second'), new LinkTypeDefinition(id: 'blocks', label: 'Blocks', inverseLabel: ' ')],
            places: [new PlaceDefinition(id: 'a', label: "\t", status: PlaceStatus::Open)],
        );

        self::assertSame([
            'roles: "Project manager" is not a valid identifier (lowercase letters, digits and underscores, starting with a letter).',
            'places.a.label: a label is required.',
            'fields.url.label: a label is required.',
            'links: "2nd" is not a valid identifier (lowercase letters, digits and underscores, starting with a letter).',
            'links.blocks.inverseLabel: cannot be blank, leave it null for a link that reads the same from both sides.',
            'workflows: "My workflow" is not a valid identifier (lowercase letters, digits and underscores, starting with a letter).',
            'workflows.My workflow.label: a label is required.',
            'workflows.My workflow.transitions: "Go!" is not a valid identifier (lowercase letters, digits and underscores, starting with a letter).',
            'workflows.My workflow.transitions.Go!.label: a label is required.',
        ], $this->messages($definition));
    }

    public function testAFieldThatOffersChoicesHasSomeAndTheOthersNone(): void
    {
        $definition = DefinitionFactory::definition(fields: [
            new FieldDefinition(id: 'environment', label: 'Environment', type: FieldType::Choice),
            new FieldDefinition(id: 'url', label: 'URL', type: FieldType::Url, options: ['a' => 'A']),
            new FieldDefinition(id: 'browser', label: 'Browser', type: FieldType::Select, options: ['chrome' => 'Chrome', 'safari' => ' ']),
        ]);

        self::assertSame([
            'fields.environment.options: a choice field needs options.',
            'fields.url.options: a url field cannot have options.',
            'fields.browser.options: options map a value to a non-empty label.',
        ], $this->messages($definition));
    }

    public function testAnActionStartsSomewhereAndIsForTheRolesHeldToTheWorkflow(): void
    {
        $workflow = new WorkflowDefinition(id: 'w', label: 'W', initial: 'a', places: ['a', 'b'], transitions: [
            new TransitionDefinition(id: 'orphan', label: 'Orphan', from: [], to: 'b', roles: []),
            new TransitionDefinition(id: 'back', label: 'Back', from: ['b'], to: 'a', roles: ['developer'], commentPrompt: 'Why?', description: '  '),
            new TransitionDefinition(id: 'skip', label: 'Skip', from: ['a'], to: 'b', roles: ['project_manager', 'client']),
        ]);

        self::assertSame([
            'workflows.w.transitions.orphan.from: at least one source place is required.',
            'workflows.w.transitions.back.commentPrompt: only makes sense when a comment is required.',
            'workflows.w.transitions.back.description: cannot be blank, leave it null instead.',
            'workflows.w.transitions.skip.roles: role "project_manager" moves issues freely: it takes no action.',
        ], $this->messages(DefinitionFactory::definition(workflows: [$workflow])));
    }

    public function testNothingIsListedTwice(): void
    {
        $workflow = new WorkflowDefinition(id: 'w', label: 'W', initial: 'a', places: ['a', 'b', 'a'], transitions: [
            new TransitionDefinition(id: 'go', label: 'Go', from: ['a', 'a'], to: 'b', roles: ['developer', 'developer']),
        ]);
        $definition = DefinitionFactory::definition(workflows: [$workflow], permissions: ['issue.create' => ['client', 'client']]);

        self::assertSame([
            'permissions.issue.create: "client" is listed twice.',
            'workflows.w.places: "a" is listed twice.',
            'workflows.w.transitions.go.from: "a" is listed twice.',
            'workflows.w.transitions.go.roles: "developer" is listed twice.',
        ], $this->messages($definition));
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

    public function testAPlaceStandsBesideAPlaceOfThePathAndNeverTheInitialOne(): void
    {
        $workflow = new WorkflowDefinition(id: 'w', label: 'W', initial: 'a', places: ['a', 'b', 'c', 'd'], transitions: [], aside: ['a' => 'b', 'c' => 'd', 'd' => 'b', 'e' => 'b']);

        self::assertSame([
            'workflows.w.aside: the initial place "a" is where the path starts.',
            'workflows.w.aside: "c" stands beside a place of the path, which "d" is not.',
            'workflows.w.aside: unknown place "e".',
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
