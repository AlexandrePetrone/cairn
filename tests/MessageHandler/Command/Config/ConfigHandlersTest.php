<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Config;

use App\Automation\RuleReferences;
use App\Config\ConfigRegistry;
use App\Config\ConfigValidator;
use App\Config\Definition\InstanceDefinition;
use App\Config\FieldType;
use App\Config\TransitionStyle;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Transition;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Automation\DeleteRule;
use App\Message\Command\Config\AddWorkflowPlace;
use App\Message\Command\Config\CreateField;
use App\Message\Command\Config\CreateLinkType;
use App\Message\Command\Config\CreatePlace;
use App\Message\Command\Config\CreateRole;
use App\Message\Command\Config\CreateTransition;
use App\Message\Command\Config\CreateWorkflow;
use App\Message\Command\Config\DeleteField;
use App\Message\Command\Config\DeleteLinkType;
use App\Message\Command\Config\DeletePlace;
use App\Message\Command\Config\DeleteRole;
use App\Message\Command\Config\DeleteTransition;
use App\Message\Command\Config\DeleteWorkflow;
use App\Message\Command\Config\GrantPermission;
use App\Message\Command\Config\RemoveWorkflowPlace;
use App\Message\Command\Config\RevokePermission;
use App\Message\Command\Config\UpdateField;
use App\Message\Command\Config\UpdateLinkType;
use App\Message\Command\Config\UpdatePlace;
use App\Message\Command\Config\UpdateRole;
use App\Message\Command\Config\UpdateTransition;
use App\Message\Command\Config\UpdateWorkflow;
use App\Message\Command\Issue\AddLink;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\CommandBus;
use App\Message\Query\Issue\SearchIssues;
use App\MessageHandler\Command\Config\ConfigChangeException;
use App\MessageHandler\Command\Config\ConfigDeletions;
use App\Security\Permission;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The commands that change the configuration, on the test one: three
 * roles, the workflow `bug` (new, in_progress, to_test, done) and the
 * issues of the shop. Each must leave a configuration the application can
 * run, and say what it did to the issues.
 */
final class ConfigHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private ConfigRegistry $registry;
    private IssueFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->registry = self::getContainer()->get(ConfigRegistry::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
    }

    /**
     * No command checks the configuration after it wrote: whatever a test
     * dispatched, what is left must be a configuration the application can run.
     */
    protected function tearDown(): void
    {
        self::assertSame([], array_map(strval(...), (new ConfigValidator())->validate($this->definition())), 'the commands left a sound configuration');
        parent::tearDown();
    }

    public function testARoleGetsAKeyFromItsLabelAndComesLast(): void
    {
        $role = $this->commands->dispatch(new CreateRole('Client (lecture seule)'));
        $twin = $this->commands->dispatch(new CreateRole('Client (lecture seule)'));

        self::assertSame('client_lecture_seule', $role->getId());
        self::assertSame('client_lecture_seule_2', $twin->getId());
        self::assertSame(['project_manager', 'developer', 'client', 'client_lecture_seule', 'client_lecture_seule_2'], $this->definition()->roleIds());
    }

    public function testARoleIsRenamedAndMovedAmongTheOthers(): void
    {
        $this->commands->dispatch(new UpdateRole('client', label: 'Customer', followsNewIssues: true, position: 0));

        $definition = $this->definition();
        self::assertSame(['client', 'project_manager', 'developer'], $definition->roleIds());
        self::assertSame('Customer', $definition->role('client')->label);
        self::assertTrue($definition->role('client')->followsNewIssues);
    }

    public function testInternalOnlyFollowsWhoHoldsTheRoleAndWhatItGrants(): void
    {
        // Claire, external, holds the role of client.
        $this->refused('admin.refused.config.role_held_by_externals', new UpdateRole('client', internalOnly: true));
        // The managers configure projects, which only internal roles may.
        $this->refused('admin.refused.config.role_has_internal_permissions', new UpdateRole('project_manager', internalOnly: false));
        self::assertTrue($this->definition()->role('project_manager')->internalOnly, 'a refusal changes nothing');

        $this->commands->dispatch(new RevokePermission('developer', Permission::IssueChangeTracker));
        $this->commands->dispatch(new RevokePermission('developer', Permission::IssueDelete));
        $this->commands->dispatch(new UpdateRole('developer', internalOnly: false));
        self::assertFalse($this->definition()->role('developer')->internalOnly);
    }

    public function testPermissionsAreGrantedOneByOne(): void
    {
        $this->commands->dispatch(new GrantPermission('client', Permission::IssueRank));
        $this->commands->dispatch(new GrantPermission('client', Permission::IssueRank));
        self::assertSame(['project_manager', 'developer', 'client'], $this->definition()->rolesGranted(Permission::IssueRank), 'in the order of the roles, once');

        $this->commands->dispatch(new RevokePermission('developer', Permission::IssueRank));
        self::assertSame(['project_manager', 'client'], $this->definition()->rolesGranted(Permission::IssueRank));

        $this->refused('admin.refused.config.permission_internal_only', new GrantPermission('client', Permission::IssueDelete));
        $this->refused('admin.refused.not_found', new GrantPermission('nobody', Permission::IssueRank));
    }

    public function testARoleGoesOnlyOnceNobodyHoldsItAndNoActionNamesIt(): void
    {
        $this->refused('admin.refused.config.role_held', new DeleteRole('client'));

        $auditor = $this->commands->dispatch(new CreateRole('Auditor'));
        $this->commands->dispatch(new GrantPermission($auditor->getId(), Permission::IssueCreate));
        $this->commands->dispatch(new UpdateTransition('bug', 'accept', roles: ['client', 'project_manager', 'auditor']));
        $this->refused('admin.refused.config.role_in_actions', new DeleteRole('auditor'));

        $this->commands->dispatch(new UpdateTransition('bug', 'accept', roles: ['client', 'project_manager']));
        $this->commands->dispatch(new DeleteRole('auditor'));

        self::assertFalse($this->definition()->hasRole('auditor'));
        self::assertSame(['project_manager', 'developer', 'client'], $this->definition()->rolesGranted(Permission::IssueCreate), 'its permissions went with it');
    }

    public function testARoleStaysWhileARuleAimsAtItsReferent(): void
    {
        $this->commands->dispatch(new CreateRole('Triage'));
        $project = (int) $this->fixtures->access->shop->getId();
        $rule = $this->commands->dispatch(new CreateRule($project, 'Hand over to triage', null, [
            'trigger' => ['type' => 'issue_created'],
            'actions' => [['type' => 'assign', 'params' => ['target' => 'referent:triage']]],
        ]));

        $references = self::getContainer()->get(RuleReferences::class);
        self::assertSame(['Hand over to triage (Online shop)'], $references->toRole('triage'));
        self::assertSame([], $references->toRole('client'));
        $this->refused('admin.refused.config.role_in_rules', new DeleteRole('triage'));

        $this->commands->dispatch(new DeleteRule((int) $rule->getId()));
        $this->commands->dispatch(new DeleteRole('triage'));
        self::assertFalse($this->definition()->hasRole('triage'));
    }

    public function testTheRulesThatNameAStepOrAnActionOfAWorkflowAreFound(): void
    {
        $project = (int) $this->fixtures->access->shop->getId();
        $bugs = (int) $this->fixtures->bugs->getId();
        $this->commands->dispatch(new CreateRule($project, 'Close what was tested', $bugs, [
            'trigger' => ['type' => 'inactive_for', 'params' => ['days' => 30]],
            'conditions' => [['type' => 'place', 'params' => ['is' => ['to_test', 'in_progress']]]],
            'actions' => [['type' => 'move', 'params' => ['place' => 'done']]],
        ]));
        $this->commands->dispatch(new CreateRule($project, 'Thank on delivery', $bugs, [
            'trigger' => ['type' => 'transition_applied', 'params' => ['transition' => 'deliver']],
            'actions' => [['type' => 'comment', 'params' => ['body' => 'Delivered.']]],
        ]));
        $this->commands->dispatch(new CreateWorkflow('Support', 'new'));

        $references = self::getContainer()->get(RuleReferences::class);
        self::assertSame(['Close what was tested (Online shop)'], $references->toPlace('bug', 'to_test'));
        self::assertSame(['Close what was tested (Online shop)'], $references->toPlace('bug', 'done'), 'the step an action moves to counts too');
        self::assertSame([], $references->toPlace('bug', 'new'));
        self::assertSame([], $references->toPlace('support', 'to_test'), 'no tracker of the shop follows that workflow');
        self::assertSame(['Thank on delivery (Online shop)'], $references->toTransition('bug', 'deliver'));
        self::assertSame([], $references->toTransition('bug', 'start'));
    }

    public function testAStatusGivenToAPlaceIsAtOnceTheStatusOfItsIssuesAndTouchesNone(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client, title: 'Waiting to be read');
        $before = $issue->getUpdatedAt()->format('Y-m-d H:i:s');
        $open = fn (): array => array_map(
            static fn (Issue $listed): string => $listed->getTitle(),
            self::queryBus()->ask(new SearchIssues($this->fixtures->manager))->items,
        );
        self::assertSame(['Waiting to be read'], $open());

        $this->commands->dispatch(new UpdatePlace('new', label: 'Inbox', status: PlaceStatus::Closed));

        self::assertSame('Inbox', $this->definition()->place('new')->label);
        self::assertSame(PlaceStatus::Closed, $this->definition()->place('new')->status);
        self::assertSame([], $open(), 'an issue is finished by the status of its place: the lists follow at once');
        self::assertSame(['Waiting to be read'], array_map(static fn (Issue $listed): string => $listed->getTitle(), self::queryBus()->ask(new SearchIssues($this->fixtures->manager, closed: true))->items));

        $this->entityManager->clear();
        $untouched = $this->reload($issue);
        self::assertNull($untouched->getClosedAt(), 'nobody closed it: it has no closing date');
        self::assertSame($before, $untouched->getUpdatedAt()->format('Y-m-d H:i:s'), 'no issue is written');

        $this->commands->dispatch(new UpdatePlace('new', status: PlaceStatus::Open));
        self::assertSame(['Waiting to be read'], $open());
    }

    public function testPlacesAreOrderedAndDeletedOnlyWhenNoWorkflowUsesThem(): void
    {
        $parked = $this->commands->dispatch(new CreatePlace('Parked', PlaceStatus::Waiting));
        self::assertSame(['new', 'in_progress', 'to_test', 'done', 'parked'], array_keys($this->definition()->places));

        $this->commands->dispatch(new UpdatePlace($parked->getId(), position: 1));
        self::assertSame(['new', 'parked', 'in_progress', 'to_test', 'done'], array_keys($this->definition()->places));

        $this->refused('admin.refused.config.place_used', new DeletePlace('to_test'));
        $this->commands->dispatch(new DeletePlace('parked'));
        self::assertSame(['new', 'in_progress', 'to_test', 'done'], array_keys($this->definition()->places));
    }

    public function testAFieldKeepsItsTypeAndItsChoicesFollowIt(): void
    {
        $this->refused('admin.refused.config.choices_required', new CreateField('Browser', FieldType::Select));
        $this->refused('admin.refused.config.choices_not_offered', new CreateField('Browser', FieldType::Text, options: ['a' => 'A']));

        $field = $this->commands->dispatch(new CreateField('Browser', FieldType::Choice, options: ['chrome' => 'Chrome', 'safari' => 'Safari'], help: 'The one you use.'));
        $this->commands->dispatch(new UpdateField($field->getId(), label: 'Web browser', options: ['safari' => 'Safari', 'firefox' => 'Firefox'], help: '', internalOnly: true));

        $defined = $this->definition()->field('browser');
        self::assertSame('Web browser', $defined->label);
        self::assertSame(['safari' => 'Safari', 'firefox' => 'Firefox'], $defined->options, 'in the order given');
        self::assertNull($defined->help, 'an empty help erases it');
        self::assertTrue($defined->internalOnly);

        // A value made of digits is a value like another, whatever PHP does of the key.
        $this->commands->dispatch(new UpdateField('environment', options: ['1' => 'One', 'prod' => 'Production']));
        self::assertSame('One', $this->definition()->field('environment')->display('1'));

        $this->refused('admin.refused.config.choices_required', new UpdateField('environment', options: []));
        $this->refused('admin.refused.config.field_used', new DeleteField('environment'));
        $this->commands->dispatch(new DeleteField('browser'));
        self::assertFalse($this->definition()->hasField('browser'));
    }

    public function testALinkTypeStaysWhileLinksUseIt(): void
    {
        $type = $this->commands->dispatch(new CreateLinkType('Follows', 'Followed by'));
        $this->commands->dispatch(new UpdateLinkType($type->getId(), inverseLabel: ''));
        self::assertTrue($this->definition()->link('follows')->isSymmetric());

        $a = $this->fixtures->createIssue($this->fixtures->client, title: 'A');
        $b = $this->fixtures->createIssue($this->fixtures->client, title: 'B');
        $this->commands->dispatch(new AddLink((int) $this->fixtures->developer->getId(), $a->getKey(), 'follows', $b->getKey()));

        $this->refused('admin.refused.config.link_type_used', new DeleteLinkType('follows'));
        $this->commands->dispatch(new DeleteLinkType('related'));
        self::assertSame(['blocks', 'follows'], array_keys($this->definition()->links));
    }

    public function testAWorkflowIsBuiltPlaceByPlaceAndActionByAction(): void
    {
        $workflow = $this->commands->dispatch(new CreateWorkflow('Support request', 'new'));
        self::assertSame('support_request', $workflow->getId());
        self::assertSame(['new'], $this->definition()->workflow('support_request')->places);

        $this->commands->dispatch(new AddWorkflowPlace('support_request', 'done'));
        $this->commands->dispatch(new AddWorkflowPlace('support_request', 'in_progress', position: 1));
        $this->refused('admin.refused.config.already_a_place_of', new AddWorkflowPlace('support_request', 'done'));

        $answer = $this->commands->dispatch(new CreateTransition('support_request', 'Answer', ['new', 'in_progress'], 'done', ['developer'], TransitionStyle::Primary, true, 'What was done?', 'The request is answered.'));
        $this->commands->dispatch(new UpdateWorkflow('support_request', fields: ['url' => false, 'environment' => true]));

        $defined = $this->definition()->workflow('support_request');
        self::assertSame(['new', 'in_progress', 'done'], $defined->places);
        self::assertSame('answer', $answer->getKey());
        self::assertSame(['new', 'in_progress'], $defined->transition('answer')->from);
        self::assertSame('What was done?', $defined->transition('answer')->commentPrompt);
        self::assertSame(['url', 'environment'], array_keys($defined->fields));
        self::assertTrue($defined->fields['environment']->required);

        // The fields it asks for are replaced, those it keeps staying where they are told.
        $this->commands->dispatch(new UpdateWorkflow('support_request', label: 'Support', fields: ['environment' => false]));
        self::assertSame(['environment'], array_keys($this->definition()->workflow('support_request')->fields));
        self::assertFalse($this->definition()->workflow('support_request')->fields['environment']->required);
        self::assertSame('Support', $this->definition()->workflow('support_request')->label);
    }

    public function testARefusalListsTheWorkflowsInTheOrderOfTheInstance(): void
    {
        $this->commands->dispatch(new CreateWorkflow('Support', 'new'));
        $this->commands->dispatch(new UpdateWorkflow('support', fields: ['environment' => false]));
        // Written after Bug, and moved before it.
        $this->commands->dispatch(new UpdateWorkflow('support', position: 0));

        $deletions = self::getContainer()->get(ConfigDeletions::class);
        self::assertSame('Support, Bug', $deletions->place('new')?->parameters['%workflows%']);
        self::assertSame('Support, Bug', $deletions->field('environment')?->parameters['%workflows%']);
    }

    public function testAnActionKeepsToThePlacesOfItsWorkflowAndToOnePerPairOfPlaces(): void
    {
        $this->commands->dispatch(new CreateWorkflow('Support', 'new'));

        // A key is unique within its workflow: a second "Start" gets another.
        $again = $this->commands->dispatch(new CreateTransition('bug', 'Start', ['done'], 'new', ['developer']));
        self::assertInstanceOf(Transition::class, $again);
        self::assertSame('start_2', $again->getKey());

        $this->refused('admin.refused.config.not_a_place_of', new CreateTransition('support', 'Close', ['new'], 'done', ['developer']));
        $this->refused('admin.refused.not_found', new CreateTransition('bug', 'Close', ['new'], 'done', ['nobody']));
        // "Start" already leads from new to in_progress.
        $this->refused('admin.refused.config.pair_taken', new CreateTransition('bug', 'Take', ['new', 'to_test'], 'in_progress', ['developer']));
        $this->refused('admin.refused.config.pair_taken', new UpdateTransition('bug', 'refuse', from: ['new']));
        // Refused for a place or a role it names, an action keeps its name and where it starts.
        $this->refused('admin.refused.not_found', new UpdateTransition('bug', 'start', label: 'Begin', from: ['ghost']));
        $this->refused('admin.refused.not_found', new UpdateTransition('bug', 'start', label: 'Begin', roles: ['nobody']));

        try {
            $this->commands->dispatch(new CreateTransition('bug', 'Close', [], 'done', ['developer']));
            self::fail('An action starts from somewhere.');
        } catch (ValidationFailedException) {
        }
    }

    public function testAnActionIsChangedAndDeleted(): void
    {
        $this->commands->dispatch(new UpdateTransition('bug', 'refuse', label: 'Send back', from: ['to_test', 'done'], roles: ['client'], style: TransitionStyle::Default, requiresComment: false, description: 'Not right yet.', position: 0));

        $refuse = $this->definition()->workflow('bug')->transition('refuse');
        self::assertSame('Send back', $refuse->label);
        self::assertSame(['to_test', 'done'], $refuse->from);
        self::assertSame(['client'], $refuse->roles);
        self::assertFalse($refuse->requiresComment);
        self::assertNull($refuse->commentPrompt, 'a prompt only stays while a comment is required');
        self::assertSame('Not right yet.', $refuse->description);
        self::assertSame(['refuse', 'start', 'deliver', 'accept'], array_keys($this->definition()->workflow('bug')->transitions));

        $this->commands->dispatch(new DeleteTransition('bug', 'refuse'));
        self::assertSame(['start', 'deliver', 'accept'], array_keys($this->definition()->workflow('bug')->transitions));
        $this->refused('admin.refused.not_found', new DeleteTransition('bug', 'refuse'));
    }

    public function testTheOrderOfAWorkflowAndItsInitialPlace(): void
    {
        $this->commands->dispatch(new UpdateWorkflow('bug', initial: 'in_progress', places: ['done', 'new', 'in_progress', 'to_test']));

        self::assertSame(['done', 'new', 'in_progress', 'to_test'], $this->definition()->workflow('bug')->places);
        self::assertSame('in_progress', $this->definition()->workflow('bug')->initial);

        $this->refused('admin.refused.config.other_places', new UpdateWorkflow('bug', places: ['new', 'in_progress', 'done']));
        $this->commands->dispatch(new CreatePlace('Parked', PlaceStatus::Waiting));
        $this->refused('admin.refused.config.not_a_place_of', new UpdateWorkflow('bug', initial: 'parked'));
    }

    public function testAPlaceLeavesAWorkflowWithItsActionsOnceItsIssuesWentElsewhere(): void
    {
        $developer = (int) $this->fixtures->developer->getId();
        $tested = $this->fixtures->createIssue($this->fixtures->client, title: 'Being tested');
        $this->commands->dispatch(new ApplyTransition($developer, $tested->getKey(), 'start'));
        $this->commands->dispatch(new ApplyTransition($developer, $tested->getKey(), 'deliver'));
        $untouched = $this->fixtures->createIssue($this->fixtures->client, title: 'New');

        $this->refused('admin.refused.config.initial_place', new RemoveWorkflowPlace('bug', 'new', 'done'));
        $this->refused('admin.refused.config.issues_to_move', new RemoveWorkflowPlace('bug', 'to_test'));
        $this->refused('admin.refused.config.issues_to_move', new RemoveWorkflowPlace('bug', 'to_test', 'to_test'));

        $this->commands->dispatch(new RemoveWorkflowPlace('bug', 'to_test', 'done'));

        $bug = $this->definition()->workflow('bug');
        self::assertSame(['new', 'in_progress', 'done'], $bug->places);
        self::assertSame(['start'], array_keys($bug->transitions), 'the action that led there and those that started from there only went with it');
        self::assertTrue($this->definition()->hasPlace('to_test'), 'the place stays in the instance');

        $this->entityManager->clear();
        $moved = $this->reload($tested);
        self::assertSame('done', $moved->getPlace());
        self::assertNotNull($moved->getClosedAt());
        self::assertSame('new', $this->reload($untouched)->getPlace());

        // Nothing uses the place any more: it may go.
        $this->commands->dispatch(new DeletePlace('to_test'));
        self::assertFalse($this->definition()->hasPlace('to_test'));
    }

    public function testAnActionThatHasOtherPlacesToStartFromStays(): void
    {
        $this->commands->dispatch(new UpdateTransition('bug', 'refuse', from: ['to_test', 'done']));
        $this->commands->dispatch(new DeleteTransition('bug', 'deliver'));
        $this->commands->dispatch(new DeleteTransition('bug', 'accept'));

        $this->commands->dispatch(new RemoveWorkflowPlace('bug', 'to_test'));

        self::assertSame(['done'], $this->definition()->workflow('bug')->transition('refuse')->from);
    }

    public function testAWorkflowGoesOnlyOnceNoTrackerFollowsIt(): void
    {
        $this->refused('admin.refused.config.workflow_followed', new DeleteWorkflow('bug'));

        $this->commands->dispatch(new CreateWorkflow('Support', 'new'));
        $this->commands->dispatch(new AddWorkflowPlace('support', 'done'));
        $this->commands->dispatch(new CreateTransition('support', 'Close', ['new'], 'done', ['developer']));
        $this->commands->dispatch(new DeleteWorkflow('support'));

        self::assertSame(['bug'], array_keys($this->definition()->workflows));
    }

    public function testARefusalIsSaidInTheLanguageOfTheInstance(): void
    {
        try {
            $this->commands->dispatch(new DeleteRole('client'));
            self::fail('Claire holds the role.');
        } catch (ConfigChangeException $e) {
            self::assertSame('An access carries the role "Client": revoke it or give it another role first.', $e->trans(self::getContainer()->get(TranslatorInterface::class)));
        }
    }

    private function definition(): InstanceDefinition
    {
        return $this->registry->definition();
    }

    private function reload(Issue $issue): Issue
    {
        return $this->entityManager->find(Issue::class, $issue->getId()) ?? throw new \LogicException('The issue exists.');
    }

    private function refused(string $reason, object $command): void
    {
        $before = $this->definition();
        try {
            $this->commands->dispatch($command);
            self::fail(\sprintf('%s was accepted.', $command::class));
        } catch (ConfigChangeException $e) {
            // The key of the reason, not its sentence.
            self::assertSame($reason, $e->trans(new IdentityTranslator()), $e->getMessage());
        }
        // A handler checks before it writes: what a refused one left in the
        // entity manager, the next command of the process would write.
        $this->entityManager->flush();
        $this->registry->reset();
        self::assertEquals($before, $this->definition(), 'a refused command leaves the configuration as it was, and nothing to write');
    }
}
