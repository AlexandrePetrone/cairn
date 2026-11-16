<?php

declare(strict_types=1);

/*
 * Example instance configuration.
 *
 * The real file lives outside the repository and is pointed to by the
 * CAIRN_CONFIG environment variable. It is never read at runtime: run
 * `bin/console app:config:sync` to validate it and store it as a snapshot,
 * which is what the application uses.
 *
 * Every `id` is a stable technical identifier stored in database; labels
 * can change freely. Declaration order matters for roles (broadest first)
 * and for places (the filters and groups of the lists; the places of a
 * workflow are its kanban columns).
 */

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\FieldType;
use App\Config\MigrationPolicy;
use App\Config\TransitionStyle;
use App\Entity\Enum\PlaceStatus;
use App\Security\Permission;

$internal = ['project_manager', 'developer'];
$internalAndClient = ['project_manager', 'developer', 'client'];
$clientFacing = ['project_manager', 'client'];
$managers = ['project_manager'];

return new InstanceDefinition(
    // What the sync does with issues sitting on a place that disappears
    // without a rule in the `migrations` of its workflow: refuse (Strict) or
    // move them to the workflow's fallback place (Fallback).
    migrationPolicy: MigrationPolicy::Strict,

    // Broadest role first: a direct grant on a project may only widen a role
    // inherited from the workspace. `internalOnly` roles cannot be granted
    // to external users. Internal users without a role see everything but
    // act nowhere.
    roles: [
        new RoleDefinition(id: 'project_manager', label: 'Project manager', internalOnly: true, followsNewIssues: true),
        new RoleDefinition(id: 'developer', label: 'Developer', internalOnly: true),
        new RoleDefinition(id: 'client', label: 'Client'),
        new RoleDefinition(id: 'client_readonly', label: 'Client (read-only)'),
    ],

    // Permissions outside workflows. Administrators hold all of them.
    permissions: [
        Permission::IssueCreate->value => $internalAndClient,
        // Authors always edit their own issues and comments; these two
        // grant editing the content written by others.
        Permission::IssueEdit->value => $managers,
        Permission::IssueChangeTracker->value => $internal,
        // Deleting an issue erases its comments, files, links and history for good.
        Permission::IssueDelete->value => $internal,
        // Put an issue on any step, even one no action leads to: internal users
        // may leave the workflow shown to clients, and the journal says so.
        Permission::IssueForcePlace->value => $internal,
        Permission::IssueAssign->value => $internal,
        // The order of a kanban column is shared: add a client role to let clients order their backlog.
        Permission::IssueRank->value => $internal,
        Permission::IssueSetCriticality->value => $internalAndClient,
        Permission::IssueSetPrivate->value => $internal,
        Permission::IssueSetRelease->value => $internal,
        Permission::IssueLink->value => $internal,
        Permission::CommentCreate->value => $internalAndClient,
        Permission::CommentSetPrivate->value => $internal,
        Permission::CommentEdit->value => $managers,
        Permission::ProjectConfigure->value => $managers,
        Permission::ProjectManageMembers->value => $managers,
        Permission::ReleaseManage->value => $managers,
        // Budgets are per project, for those that set a unit (days or hours):
        // managers create them and write their lines, whoever may view them
        // reads them, on their pages and on the issues their lines name.
        Permission::BudgetManage->value => $managers,
        Permission::BudgetView->value => ['project_manager', 'developer', 'client', 'client_readonly'],
    ],

    // Global fields; each workflow picks the ones it uses. An estimate is
    // not a field: it is a line of a budget of the project, written and
    // approved on the budgets pages, without any tie to these workflows.
    fields: [
        new FieldDefinition(
            id: 'environment',
            label: 'Environment',
            type: FieldType::Choice,
            options: ['prod' => 'Production', 'preprod' => 'Pre-production'],
        ),
        new FieldDefinition(
            id: 'url',
            label: 'Affected URL',
            type: FieldType::Url,
            placeholder: 'https://…',
            help: 'The URL where the problem occurs.',
        ),
        new FieldDefinition(
            id: 'steps',
            label: 'Steps to reproduce',
            type: FieldType::Textarea,
            placeholder: '1. I go to… 2. I click on… 3. I see…',
            help: 'What you did, what you expected, what happened.',
        ),
        new FieldDefinition(
            id: 'browser',
            label: 'Context',
            type: FieldType::Text,
            placeholder: 'Chrome 130 on macOS, Safari on iPhone, account used…',
        ),
        new FieldDefinition(
            id: 'goal',
            label: 'Goal',
            type: FieldType::Textarea,
            placeholder: 'Which problem does this feature solve, and for whom?',
            help: 'The need rather than the solution: it helps us offer the right answer.',
        ),
        new FieldDefinition(
            id: 'due_date',
            label: 'Desired due date',
            type: FieldType::Date,
        ),
    ],

    // Without an inverse label the link reads the same from both sides.
    links: [
        new LinkTypeDefinition(id: 'related', label: 'Related to'),
        new LinkTypeDefinition(id: 'duplicates', label: 'Duplicates', inverseLabel: 'Duplicated by'),
        new LinkTypeDefinition(id: 'blocks', label: 'Blocks', inverseLabel: 'Blocked by'),
    ],

    // Every step an issue may stand on, declared once: each workflow names
    // the ones it uses, so a step is the same in every category and the issue
    // lists filter and group by it across them. This order is the order of
    // those filters and groups; the columns of a kanban follow the workflow.
    places: [
        new PlaceDefinition(id: 'new', label: 'New', status: PlaceStatus::Open),
        // Waiting on the client: internal users cannot qualify without more details.
        new PlaceDefinition(id: 'needs_info', label: 'Info requested', status: PlaceStatus::Waiting),
        new PlaceDefinition(id: 'qualified', label: 'Qualified', status: PlaceStatus::Open),
        new PlaceDefinition(id: 'to_estimate', label: 'To estimate', status: PlaceStatus::Open),
        // Waiting on the client: the estimate was sent.
        new PlaceDefinition(id: 'to_approve', label: 'Estimate to approve', status: PlaceStatus::Waiting),
        new PlaceDefinition(id: 'planned', label: 'Planned', status: PlaceStatus::InProgress),
        new PlaceDefinition(id: 'in_progress', label: 'In progress', status: PlaceStatus::InProgress),
        // Waiting on a third party or a prerequisite: internal users hold the issue.
        new PlaceDefinition(id: 'blocked', label: 'Blocked', status: PlaceStatus::Waiting),
        new PlaceDefinition(id: 'to_test', label: 'To test', status: PlaceStatus::Waiting),
        new PlaceDefinition(id: 'to_release', label: 'To release', status: PlaceStatus::InProgress),
        // One way to close whatever the workflow: lists filter and group by step, across categories.
        new PlaceDefinition(id: 'done', label: 'Done', status: PlaceStatus::Closed),
        new PlaceDefinition(id: 'declined', label: 'Declined', status: PlaceStatus::Closed),
    ],

    // The places of a workflow are ids of the list above, in the order of
    // its kanban columns. `migrations: ['old' => 'new']` says where the issues
    // of a place the workflow no longer has go on sync.
    workflows: [
        new WorkflowDefinition(
            id: 'bug',
            label: 'Bug',
            initial: 'new',
            places: ['new', 'needs_info', 'qualified', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
            transitions: [
                new TransitionDefinition(
                    id: 'ask_info',
                    label: 'Ask for details',
                    from: ['new'],
                    to: 'needs_info',
                    roles: $internal,
                    requiresComment: true,
                    commentPrompt: 'Say what we are missing to reproduce the problem.',
                    description: 'Something is missing to understand the bug: the issue waits for the client\'s answer.',
                ),
                new TransitionDefinition(id: 'provide_info', label: 'Answer', from: ['needs_info'], to: 'new', roles: $internalAndClient, style: TransitionStyle::Primary, description: 'The details are given: the issue goes back to the team.'),
                new TransitionDefinition(id: 'qualify', label: 'Qualify', from: ['new'], to: 'qualified', roles: $internal, description: 'The bug is understood and reproduced: it joins the team\'s queue.'),
                new TransitionDefinition(id: 'reject', label: 'Reject', from: ['new', 'needs_info', 'qualified'], to: 'declined', roles: $internal, style: TransitionStyle::Danger, description: 'It is not a bug, or it will not be fixed: the issue is closed.'),
                new TransitionDefinition(id: 'start', label: 'Start', from: ['new', 'qualified'], to: 'in_progress', roles: $internal, description: 'The fix begins.'),
                new TransitionDefinition(
                    id: 'block',
                    label: 'Block',
                    from: ['in_progress'],
                    to: 'blocked',
                    roles: $internal,
                    requiresComment: true,
                    commentPrompt: 'Say what is blocking and who must lift it.',
                    description: 'The fix cannot move forward: it depends on a third party or a prerequisite.',
                ),
                new TransitionDefinition(id: 'resume', label: 'Resume', from: ['blocked'], to: 'in_progress', roles: $internal, description: 'The blocker is lifted: the fix resumes.'),
                new TransitionDefinition(id: 'deliver', label: 'Deliver for testing', from: ['in_progress'], to: 'to_test', roles: $internal, description: 'The fix is on pre-production, ready to be checked.'),
                new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'to_release', roles: $clientFacing, style: TransitionStyle::Primary, description: 'The fix is accepted: it waits for its release to production.'),
                new TransitionDefinition(
                    id: 'refuse',
                    label: 'Refuse',
                    from: ['to_test'],
                    to: 'in_progress',
                    roles: $clientFacing,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'Explain what is wrong: a comment is required to refuse.',
                    description: 'The fix is not right: the issue goes back to development, with the comment that says why.',
                ),
                new TransitionDefinition(id: 'release', label: 'Release to production', from: ['to_release'], to: 'done', roles: $internal, style: TransitionStyle::Primary, description: 'The fix is in production: the issue is closed. It can be reopened if the problem comes back.'),
                new TransitionDefinition(id: 'reopen', label: 'Reopen', from: ['done'], to: 'in_progress', roles: $clientFacing, description: 'The problem came back: the issue goes back to development.'),
                new TransitionDefinition(id: 'reopen_rejected', label: 'Reopen', from: ['declined'], to: 'new', roles: $internal, description: 'The issue is reviewed again from the start.'),
            ],
            fields: [
                new FieldUsage('environment', required: true),
                new FieldUsage('url'),
                new FieldUsage('steps'),
                new FieldUsage('browser'),
            ],
        ),

        new WorkflowDefinition(
            id: 'evolution',
            label: 'Feature',
            initial: 'new',
            places: ['new', 'to_estimate', 'to_approve', 'planned', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
            transitions: [
                new TransitionDefinition(id: 'estimate', label: 'To estimate', from: ['new'], to: 'to_estimate', roles: $internal, description: 'The request is understood: it goes to estimation.'),
                new TransitionDefinition(id: 'decline', label: 'Decline', from: ['new', 'to_estimate'], to: 'declined', roles: $internal, style: TransitionStyle::Danger, description: 'The feature will not be built: the issue is closed.'),
                new TransitionDefinition(id: 'submit_estimate', label: 'Submit the estimate', from: ['to_estimate'], to: 'to_approve', roles: $internal, description: 'The estimate was sent to the client: the issue waits for their decision.'),
                new TransitionDefinition(id: 'approve', label: 'Accept the estimate', from: ['to_approve'], to: 'planned', roles: $clientFacing, style: TransitionStyle::Primary, description: 'The estimate is accepted: the feature is planned.'),
                new TransitionDefinition(
                    id: 'decline_estimate',
                    label: 'Refuse the estimate',
                    from: ['to_approve'],
                    to: 'declined',
                    roles: $clientFacing,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'A comment is required to refuse the estimate.',
                    description: 'The estimate is refused: the feature will not be built and the issue is closed.',
                ),
                new TransitionDefinition(id: 'unplan', label: 'Unplan', from: ['planned'], to: 'to_estimate', roles: $internal, description: 'The plan is called into question: the feature goes back to estimation.'),
                new TransitionDefinition(id: 'start', label: 'Start', from: ['planned'], to: 'in_progress', roles: $internal, description: 'The work begins.'),
                new TransitionDefinition(
                    id: 'block',
                    label: 'Block',
                    from: ['planned', 'in_progress'],
                    to: 'blocked',
                    roles: $internal,
                    requiresComment: true,
                    commentPrompt: 'Say what is blocking and who must lift it.',
                    description: 'The feature cannot move forward: it depends on a third party or a prerequisite.',
                ),
                new TransitionDefinition(id: 'resume', label: 'Resume', from: ['blocked'], to: 'in_progress', roles: $internal, description: 'The blocker is lifted: the work resumes.'),
                new TransitionDefinition(id: 'replan', label: 'Replan', from: ['blocked'], to: 'planned', roles: $internal, description: 'The blocker is lifted: the feature goes back to the team\'s queue.'),
                new TransitionDefinition(id: 'deliver', label: 'Deliver for testing', from: ['in_progress'], to: 'to_test', roles: $internal, description: 'The feature is on pre-production, ready to be checked.'),
                new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'to_release', roles: $clientFacing, style: TransitionStyle::Primary, description: 'The feature matches the request: it waits for its release to production.'),
                new TransitionDefinition(
                    id: 'refuse',
                    label: 'Refuse',
                    from: ['to_test'],
                    to: 'in_progress',
                    roles: $clientFacing,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'Explain what does not match the request: a comment is required to refuse.',
                    description: 'The feature does not match the request: it goes back to development, with the comment that says why.',
                ),
                new TransitionDefinition(id: 'release', label: 'Release to production', from: ['to_release'], to: 'done', roles: $internal, style: TransitionStyle::Primary, description: 'The feature is in production: the issue is closed.'),
                new TransitionDefinition(id: 'reopen', label: 'Reopen', from: ['done'], to: 'in_progress', roles: $internal, description: 'The delivered feature needs more work.'),
                new TransitionDefinition(id: 'reopen_declined', label: 'Reopen', from: ['declined'], to: 'new', roles: $internal, description: 'The issue is reviewed again from the start.'),
            ],
            fields: [
                new FieldUsage('goal', required: true),
                new FieldUsage('due_date'),
            ],
        ),

        // Workflow for an internal tracker (technical task): same delivery
        // chain as client issues, but the team tests and accepts itself.
        new WorkflowDefinition(
            id: 'task',
            label: 'Internal task',
            initial: 'new',
            places: ['new', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
            transitions: [
                new TransitionDefinition(id: 'start', label: 'Start', from: ['new'], to: 'in_progress', roles: $internal, description: 'The task is taken on.'),
                new TransitionDefinition(
                    id: 'block',
                    label: 'Block',
                    from: ['in_progress'],
                    to: 'blocked',
                    roles: $internal,
                    requiresComment: true,
                    commentPrompt: 'Say what is blocking and who must lift it.',
                    description: 'The task cannot move forward: it depends on a third party or a prerequisite.',
                ),
                new TransitionDefinition(id: 'resume', label: 'Resume', from: ['blocked'], to: 'in_progress', roles: $internal, description: 'The blocker is lifted: the task resumes.'),
                new TransitionDefinition(id: 'deliver', label: 'Deliver for testing', from: ['in_progress'], to: 'to_test', roles: $internal, description: 'The task is on pre-production, ready to be checked by the team.'),
                new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'to_release', roles: $internal, style: TransitionStyle::Primary, description: 'The internal testing passed: the task waits for its release to production.'),
                new TransitionDefinition(
                    id: 'refuse',
                    label: 'Refuse',
                    from: ['to_test'],
                    to: 'in_progress',
                    roles: $internal,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'Explain what is wrong: a comment is required to refuse.',
                    description: 'The internal testing failed: the task goes back in progress.',
                ),
                new TransitionDefinition(id: 'release', label: 'Release to production', from: ['to_release'], to: 'done', roles: $internal, style: TransitionStyle::Primary, description: 'The task is in production: it is done.'),
                new TransitionDefinition(id: 'cancel', label: 'Cancel', from: ['new', 'in_progress', 'blocked'], to: 'declined', roles: $internal, style: TransitionStyle::Danger, description: 'The task will not be done.'),
                new TransitionDefinition(id: 'reopen', label: 'Reopen', from: ['done'], to: 'new', roles: $internal, description: 'The task is back to do.'),
                new TransitionDefinition(id: 'reopen_cancelled', label: 'Reopen', from: ['declined'], to: 'new', roles: $internal, description: 'The task is to do again.'),
            ],
            fields: [
                new FieldUsage('due_date'),
            ],
        ),
    ],
);
