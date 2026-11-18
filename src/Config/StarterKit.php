<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Entity\ProjectTemplate;
use App\Entity\TemplateTracker;
use App\Message\Command\Config\InstallConfiguration;
use App\Message\Command\Template\CreateTemplate;
use App\Message\Command\Template\CreateTemplateRule;
use App\Message\Command\Template\CreateTemplateTracker;
use App\Message\CommandBus;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a new instance starts with: the roles, permissions, steps,
 * workflows, fields and link types of a team working with its clients,
 * and a project template to create the first project with. Installed once, into an instance that has no configuration,
 * and never applied again: what it gave is then data like any other,
 * changed from the administration, and a later version of the kit changes
 * no instance that exists.
 *
 * The ids are the same in every language; the labels come from the
 * `starter` translation catalogue, read in the language of the instance
 * when the kit is installed.
 */
final class StarterKit
{
    private const array INTERNAL = ['project_manager', 'developer'];
    private const array INTERNAL_AND_CLIENT = ['project_manager', 'developer', 'client'];
    private const array CLIENT_FACING = ['project_manager', 'client'];
    private const array MANAGERS = ['project_manager'];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ConfigRegistry $registry,
        private readonly CommandBus $commands,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Installs the kit, unless the instance already has a configuration.
     * The configuration and its template come together or not at all:
     * each command has its transaction, and this one holds them all, so a
     * template that cannot be made leaves an instance still to install.
     *
     * @return bool false when there was a configuration, left as it is
     */
    public function install(): bool
    {
        if (!$this->registry->definition()->isEmpty()) {
            return false;
        }

        $definition = $this->definition();
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->commands->dispatch(new InstallConfiguration($definition));
            $this->installTemplate($definition);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            // What was written was taken back: neither the entity manager nor the registry may go on holding it.
            $this->entityManager->clear();
            $this->registry->reset();

            throw $e;
        }

        return true;
    }

    /**
     * What the automation rules of a project of the kit say: the name of
     * each, whether it is limited to the bugs, its definition, whether it
     * is switched on. They aim at the referent of a role, never at a
     * person, so that a project template may carry them.
     *
     * @return list<array{string, bool, array<string, mixed>, bool}>
     */
    public function rules(): array
    {
        $toTheManager = [['type' => 'assign', 'params' => ['target' => 'referent:project_manager']]];
        $leftWithTheClient = [['type' => 'assignee', 'params' => ['is' => 'external']], ['type' => 'code', 'params' => ['is' => 'none']]];

        return [
            [$this->label('template.rule.to_manager'), false, [
                'trigger' => ['type' => 'issue_created'],
                'conditions' => [['type' => 'assignee', 'params' => ['is' => 'nobody']]],
                'actions' => $toTheManager,
            ], true],
            [$this->label('template.rule.client_answers'), false, [
                'trigger' => ['type' => 'comment_added'],
                'conditions' => [['type' => 'actor', 'params' => ['is' => 'external']], ['type' => 'assignee', 'params' => ['is' => 'external_or_nobody']]],
                'actions' => $toTheManager,
            ], true],
            [$this->label('template.rule.reminder'), false, [
                'trigger' => ['type' => 'inactive_for', 'params' => ['days' => 30]],
                'conditions' => $leftWithTheClient,
                'actions' => [['type' => 'comment', 'params' => ['body' => $this->label('template.rule.reminder_body')]]],
            ], true],
            [$this->label('template.rule.closing'), true, [
                'trigger' => ['type' => 'inactive_for', 'params' => ['days' => 45]],
                'conditions' => $leftWithTheClient,
                'actions' => [['type' => 'move', 'params' => ['place' => 'done']]],
            ], false],
        ];
    }

    /**
     * The project template of the kit: a tracker per workflow and the
     * rules, for the first project to start with.
     */
    private function installTemplate(InstanceDefinition $definition): void
    {
        $template = $this->commands->dispatch(new CreateTemplate($this->label('template.name'), $this->label('template.description')));
        \assert($template instanceof ProjectTemplate);
        $templateId = (int) $template->getId();

        $bugs = $this->commands->dispatch(new CreateTemplateTracker($templateId, $definition->workflow('bug')->label, 'bug', $this->label('template.tracker.bug')));
        \assert($bugs instanceof TemplateTracker);
        $this->commands->dispatch(new CreateTemplateTracker($templateId, $definition->workflow('evolution')->label, 'evolution', $this->label('template.tracker.evolution')));
        $this->commands->dispatch(new CreateTemplateTracker($templateId, $definition->workflow('task')->label, 'task', $this->label('template.tracker.task'), private: true));

        foreach ($this->rules() as [$name, $onBugs, $rule, $active]) {
            $this->commands->dispatch(new CreateTemplateRule($templateId, $name, $onBugs ? (int) $bugs->getId() : null, $rule, $active));
        }
    }

    public function definition(): InstanceDefinition
    {
        return new InstanceDefinition(
            // Broadest role first: a direct grant on a project may only widen a
            // role inherited from the workspace. Internal users without a role
            // see everything but act nowhere.
            roles: [
                new RoleDefinition('project_manager', $this->label('role.project_manager'), internalOnly: true, followsNewIssues: true),
                new RoleDefinition('developer', $this->label('role.developer'), internalOnly: true),
                new RoleDefinition('client', $this->label('role.client')),
                new RoleDefinition('client_readonly', $this->label('role.client_readonly')),
            ],

            // Permissions outside workflows. Administrators hold all of them.
            permissions: [
                Permission::IssueCreate->value => self::INTERNAL_AND_CLIENT,
                // Authors always edit their own issues and comments; these two
                // grant editing the content written by others.
                Permission::IssueEdit->value => self::MANAGERS,
                Permission::IssueChangeTracker->value => self::INTERNAL,
                Permission::IssueDelete->value => self::INTERNAL,
                Permission::IssueForcePlace->value => self::INTERNAL,
                Permission::IssueAssign->value => self::INTERNAL,
                // The order of a kanban column is shared: a client role may be given it to order its backlog.
                Permission::IssueRank->value => self::INTERNAL,
                Permission::IssueSetCriticality->value => self::INTERNAL_AND_CLIENT,
                Permission::IssueSetPrivate->value => self::INTERNAL,
                Permission::IssueSetRelease->value => self::INTERNAL,
                Permission::IssueLink->value => self::INTERNAL,
                Permission::CommentCreate->value => self::INTERNAL_AND_CLIENT,
                Permission::CommentSetPrivate->value => self::INTERNAL,
                Permission::CommentEdit->value => self::MANAGERS,
                Permission::ProjectConfigure->value => self::MANAGERS,
                Permission::ProjectManageMembers->value => self::MANAGERS,
                Permission::ReleaseManage->value => self::MANAGERS,
                Permission::BudgetManage->value => self::MANAGERS,
                Permission::BudgetView->value => ['project_manager', 'developer', 'client', 'client_readonly'],
            ],

            // An estimate is not a field: it is a line of a budget of the project.
            fields: [
                $this->field('environment', FieldType::Choice, options: ['prod', 'preprod']),
                $this->field('url', FieldType::Url, placeholder: true, help: true),
                $this->field('steps', FieldType::Textarea, placeholder: true, help: true),
                $this->field('browser', FieldType::Text, placeholder: true),
                $this->field('goal', FieldType::Textarea, placeholder: true, help: true),
                $this->field('due_date', FieldType::Date),
            ],

            // Without an inverse label the link reads the same from both sides.
            links: [
                new LinkTypeDefinition('related', $this->label('link.related.label')),
                new LinkTypeDefinition('duplicates', $this->label('link.duplicates.label'), $this->label('link.duplicates.inverse')),
                new LinkTypeDefinition('blocks', $this->label('link.blocks.label'), $this->label('link.blocks.inverse')),
            ],

            // This order is the order of the filters and of the groups of the issue lists.
            places: [
                $this->place('new', PlaceStatus::Open),
                $this->place('needs_info', PlaceStatus::Waiting),
                $this->place('qualified', PlaceStatus::Open),
                $this->place('to_estimate', PlaceStatus::Open),
                $this->place('to_approve', PlaceStatus::Waiting),
                $this->place('planned', PlaceStatus::InProgress),
                $this->place('in_progress', PlaceStatus::InProgress),
                $this->place('blocked', PlaceStatus::Waiting),
                $this->place('to_test', PlaceStatus::Waiting),
                $this->place('to_release', PlaceStatus::InProgress),
                $this->place('done', PlaceStatus::Closed),
                $this->place('declined', PlaceStatus::Closed),
            ],

            workflows: [
                new WorkflowDefinition(
                    id: 'bug',
                    label: $this->label('workflow.bug.label'),
                    initial: 'new',
                    places: ['new', 'needs_info', 'qualified', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
                    transitions: [
                        $this->transition('bug', 'ask_info', from: ['new'], to: 'needs_info', roles: self::INTERNAL, requiresComment: true),
                        $this->transition('bug', 'provide_info', from: ['needs_info'], to: 'new', roles: self::INTERNAL_AND_CLIENT, style: TransitionStyle::Primary),
                        $this->transition('bug', 'qualify', from: ['new'], to: 'qualified', roles: self::INTERNAL),
                        $this->transition('bug', 'reject', from: ['new', 'needs_info', 'qualified'], to: 'declined', roles: self::INTERNAL, style: TransitionStyle::Danger),
                        $this->transition('bug', 'start', from: ['new', 'qualified'], to: 'in_progress', roles: self::INTERNAL),
                        $this->transition('bug', 'block', from: ['in_progress'], to: 'blocked', roles: self::INTERNAL, requiresComment: true),
                        $this->transition('bug', 'resume', from: ['blocked'], to: 'in_progress', roles: self::INTERNAL),
                        $this->transition('bug', 'deliver', from: ['in_progress'], to: 'to_test', roles: self::INTERNAL),
                        $this->transition('bug', 'accept', from: ['to_test'], to: 'to_release', roles: self::CLIENT_FACING, style: TransitionStyle::Primary),
                        $this->transition('bug', 'refuse', from: ['to_test'], to: 'in_progress', roles: self::CLIENT_FACING, style: TransitionStyle::Danger, requiresComment: true),
                        $this->transition('bug', 'release', from: ['to_release'], to: 'done', roles: self::INTERNAL, style: TransitionStyle::Primary),
                        $this->transition('bug', 'reopen', from: ['done'], to: 'in_progress', roles: self::CLIENT_FACING),
                        $this->transition('bug', 'reopen_rejected', from: ['declined'], to: 'new', roles: self::INTERNAL),
                    ],
                    fields: [new FieldUsage('environment', required: true), new FieldUsage('url'), new FieldUsage('steps'), new FieldUsage('browser')],
                ),
                new WorkflowDefinition(
                    id: 'evolution',
                    label: $this->label('workflow.evolution.label'),
                    initial: 'new',
                    places: ['new', 'to_estimate', 'to_approve', 'planned', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
                    transitions: [
                        $this->transition('evolution', 'estimate', from: ['new'], to: 'to_estimate', roles: self::INTERNAL),
                        $this->transition('evolution', 'decline', from: ['new', 'to_estimate'], to: 'declined', roles: self::INTERNAL, style: TransitionStyle::Danger),
                        $this->transition('evolution', 'submit_estimate', from: ['to_estimate'], to: 'to_approve', roles: self::INTERNAL),
                        $this->transition('evolution', 'approve', from: ['to_approve'], to: 'planned', roles: self::CLIENT_FACING, style: TransitionStyle::Primary),
                        $this->transition('evolution', 'decline_estimate', from: ['to_approve'], to: 'declined', roles: self::CLIENT_FACING, style: TransitionStyle::Danger, requiresComment: true),
                        $this->transition('evolution', 'unplan', from: ['planned'], to: 'to_estimate', roles: self::INTERNAL),
                        $this->transition('evolution', 'start', from: ['planned'], to: 'in_progress', roles: self::INTERNAL),
                        $this->transition('evolution', 'block', from: ['planned', 'in_progress'], to: 'blocked', roles: self::INTERNAL, requiresComment: true),
                        $this->transition('evolution', 'resume', from: ['blocked'], to: 'in_progress', roles: self::INTERNAL),
                        $this->transition('evolution', 'replan', from: ['blocked'], to: 'planned', roles: self::INTERNAL),
                        $this->transition('evolution', 'deliver', from: ['in_progress'], to: 'to_test', roles: self::INTERNAL),
                        $this->transition('evolution', 'accept', from: ['to_test'], to: 'to_release', roles: self::CLIENT_FACING, style: TransitionStyle::Primary),
                        $this->transition('evolution', 'refuse', from: ['to_test'], to: 'in_progress', roles: self::CLIENT_FACING, style: TransitionStyle::Danger, requiresComment: true),
                        $this->transition('evolution', 'release', from: ['to_release'], to: 'done', roles: self::INTERNAL, style: TransitionStyle::Primary),
                        $this->transition('evolution', 'reopen', from: ['done'], to: 'in_progress', roles: self::INTERNAL),
                        $this->transition('evolution', 'reopen_declined', from: ['declined'], to: 'new', roles: self::INTERNAL),
                    ],
                    fields: [new FieldUsage('goal', required: true), new FieldUsage('due_date')],
                ),
                // Internal tasks: the team tests and releases them itself.
                new WorkflowDefinition(
                    id: 'task',
                    label: $this->label('workflow.task.label'),
                    initial: 'new',
                    places: ['new', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
                    transitions: [
                        $this->transition('task', 'start', from: ['new'], to: 'in_progress', roles: self::INTERNAL),
                        $this->transition('task', 'block', from: ['in_progress'], to: 'blocked', roles: self::INTERNAL, requiresComment: true),
                        $this->transition('task', 'resume', from: ['blocked'], to: 'in_progress', roles: self::INTERNAL),
                        $this->transition('task', 'deliver', from: ['in_progress'], to: 'to_test', roles: self::INTERNAL),
                        $this->transition('task', 'accept', from: ['to_test'], to: 'to_release', roles: self::INTERNAL, style: TransitionStyle::Primary),
                        $this->transition('task', 'refuse', from: ['to_test'], to: 'in_progress', roles: self::INTERNAL, style: TransitionStyle::Danger, requiresComment: true),
                        $this->transition('task', 'release', from: ['to_release'], to: 'done', roles: self::INTERNAL, style: TransitionStyle::Primary),
                        $this->transition('task', 'cancel', from: ['new', 'in_progress', 'blocked'], to: 'declined', roles: self::INTERNAL, style: TransitionStyle::Danger),
                        $this->transition('task', 'reopen', from: ['done'], to: 'new', roles: self::INTERNAL),
                        $this->transition('task', 'reopen_cancelled', from: ['declined'], to: 'new', roles: self::INTERNAL),
                    ],
                    fields: [new FieldUsage('due_date')],
                ),
            ],
        );
    }

    private function place(string $id, PlaceStatus $status): PlaceDefinition
    {
        return new PlaceDefinition($id, $this->label('place.'.$id), $status);
    }

    /**
     * @param list<string> $options the values of a choice, whose labels the catalogue gives
     */
    private function field(string $id, FieldType $type, array $options = [], bool $placeholder = false, bool $help = false): FieldDefinition
    {
        $labels = [];
        foreach ($options as $value) {
            $labels[$value] = $this->label("field.$id.option.$value");
        }

        return new FieldDefinition(
            id: $id,
            label: $this->label("field.$id.label"),
            type: $type,
            options: $labels,
            placeholder: $placeholder ? $this->label("field.$id.placeholder") : null,
            help: $help ? $this->label("field.$id.help") : null,
        );
    }

    /**
     * An action of a workflow: its label and what it does come from the
     * catalogue, and the prompt of its comment when it requires one.
     *
     * @param list<string> $from
     * @param list<string> $roles
     */
    private function transition(string $workflow, string $id, array $from, string $to, array $roles, TransitionStyle $style = TransitionStyle::Default, bool $requiresComment = false): TransitionDefinition
    {
        $key = "workflow.$workflow.transition.$id";

        return new TransitionDefinition(
            id: $id,
            label: $this->label($key.'.label'),
            from: $from,
            to: $to,
            roles: $roles,
            style: $style,
            requiresComment: $requiresComment,
            commentPrompt: $requiresComment ? $this->label($key.'.prompt') : null,
            description: $this->label($key.'.description'),
        );
    }

    private function label(string $key): string
    {
        return $this->translator->trans($key, [], 'starter');
    }
}
