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
 * and for places (kanban columns).
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
    // without a `migrateFrom` rule: refuse (Strict) or move them to the
    // workflow's fallback place (Fallback).
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
        // Put an issue on any step without an action: a way out of a mistake,
        // journaled as such, never the normal path.
        Permission::IssueForcePlace->value => $managers,
        Permission::IssueAssign->value => $internal,
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
    ],

    // Global fields; each workflow picks the ones it uses.
    fields: [
        new FieldDefinition(
            id: 'environment',
            label: 'Environment',
            type: FieldType::Select,
            options: ['prod' => 'Production', 'preprod' => 'Pre-production', 'staging' => 'Staging'],
        ),
        new FieldDefinition(
            id: 'url',
            label: 'Affected URL',
            type: FieldType::Url,
            placeholder: 'https://…',
            help: 'The URL where the problem occurs.',
        ),
        new FieldDefinition(
            id: 'browser',
            label: 'Browser / device',
            type: FieldType::Text,
            placeholder: 'Chrome 130 on macOS, Safari on iPhone…',
        ),
        new FieldDefinition(
            id: 'reproducible',
            label: 'Reproducible',
            type: FieldType::Choice,
            options: ['always' => 'Yes, every time', 'sometimes' => 'Sometimes', 'once' => 'Only once'],
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
        new FieldDefinition(
            id: 'estimate',
            label: 'Estimate (days)',
            type: FieldType::Number,
            internalOnly: true,
        ),
    ],

    // Without an inverse label the link reads the same from both sides.
    links: [
        new LinkTypeDefinition(id: 'related', label: 'Related to'),
        new LinkTypeDefinition(id: 'duplicates', label: 'Duplicates', inverseLabel: 'Duplicated by'),
        new LinkTypeDefinition(id: 'blocks', label: 'Blocks', inverseLabel: 'Blocked by'),
    ],

    workflows: [
        new WorkflowDefinition(
            id: 'bug',
            label: 'Bug',
            initial: 'new',
            places: [
                new PlaceDefinition(id: 'new', label: 'New', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'qualified', label: 'Qualified', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'in_progress', label: 'In progress', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'to_test', label: 'To test', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'done', label: 'Closed', status: PlaceStatus::Closed),
                new PlaceDefinition(id: 'rejected', label: 'Rejected', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'qualify', label: 'Qualify', from: ['new'], to: 'qualified', roles: $internal, description: 'The bug is understood and reproduced: it joins the team\'s queue.'),
                new TransitionDefinition(id: 'reject', label: 'Reject', from: ['new', 'qualified'], to: 'rejected', roles: $internal, style: TransitionStyle::Danger, description: 'It is not a bug, or it will not be fixed: the issue is closed.'),
                new TransitionDefinition(id: 'start', label: 'Start', from: ['qualified'], to: 'in_progress', roles: $internal, description: 'The fix begins.'),
                new TransitionDefinition(id: 'deliver', label: 'Deliver for testing', from: ['in_progress'], to: 'to_test', roles: $internal, description: 'The fix is on pre-production, ready to be checked.'),
                new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'done', roles: $clientFacing, style: TransitionStyle::Primary, description: 'The fix is accepted: the issue is closed. It can be reopened if the problem comes back.'),
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
                new TransitionDefinition(id: 'reopen', label: 'Reopen', from: ['done'], to: 'in_progress', roles: $clientFacing, description: 'The problem came back: the issue goes back to development.'),
                new TransitionDefinition(id: 'reopen_rejected', label: 'Reopen', from: ['rejected'], to: 'new', roles: $internal, description: 'The issue is reviewed again from the start.'),
            ],
            fields: [
                new FieldUsage('environment', required: true),
                new FieldUsage('url'),
                new FieldUsage('browser'),
                new FieldUsage('reproducible'),
                new FieldUsage('estimate'),
            ],
        ),

        new WorkflowDefinition(
            id: 'evolution',
            label: 'Feature',
            initial: 'new',
            places: [
                new PlaceDefinition(id: 'new', label: 'New', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'to_estimate', label: 'To estimate', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'planned', label: 'Planned', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'in_progress', label: 'In progress', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'to_test', label: 'To test', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'delivered', label: 'Delivered', status: PlaceStatus::Closed),
                new PlaceDefinition(id: 'declined', label: 'Declined', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'estimate', label: 'To estimate', from: ['new'], to: 'to_estimate', roles: $internal, description: 'The request is understood: it goes to estimation.'),
                new TransitionDefinition(id: 'decline', label: 'Decline', from: ['new', 'to_estimate'], to: 'declined', roles: $internal, style: TransitionStyle::Danger, description: 'The feature will not be built: the issue is closed.'),
                new TransitionDefinition(id: 'plan', label: 'Plan', from: ['to_estimate'], to: 'planned', roles: $internal, style: TransitionStyle::Primary, description: 'The estimate is accepted: the feature is planned.'),
                new TransitionDefinition(id: 'unplan', label: 'Unplan', from: ['planned'], to: 'to_estimate', roles: $internal, description: 'The plan is called into question: the feature goes back to estimation.'),
                new TransitionDefinition(id: 'start', label: 'Start', from: ['planned'], to: 'in_progress', roles: $internal, description: 'The work begins.'),
                new TransitionDefinition(id: 'deliver', label: 'Deliver for testing', from: ['in_progress'], to: 'to_test', roles: $internal, description: 'The feature is on pre-production, ready to be checked.'),
                new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'delivered', roles: $clientFacing, style: TransitionStyle::Primary, description: 'The feature matches the request: it is delivered and the issue is closed.'),
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
                new TransitionDefinition(id: 'reopen', label: 'Reopen', from: ['delivered'], to: 'in_progress', roles: $internal, description: 'The delivered feature needs more work.'),
                new TransitionDefinition(id: 'reopen_declined', label: 'Reopen', from: ['declined'], to: 'new', roles: $internal, description: 'The issue is reviewed again from the start.'),
            ],
            fields: [
                new FieldUsage('goal', required: true),
                new FieldUsage('due_date'),
                new FieldUsage('estimate'),
            ],
        ),

        // Minimal workflow for an internal tracker (technical task).
        new WorkflowDefinition(
            id: 'task',
            label: 'Internal task',
            initial: 'todo',
            places: [
                new PlaceDefinition(id: 'todo', label: 'To do', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'doing', label: 'In progress', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'done', label: 'Done', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'start', label: 'Start', from: ['todo'], to: 'doing', roles: $internal, description: 'The task is taken on.'),
                new TransitionDefinition(id: 'finish', label: 'Finish', from: ['doing'], to: 'done', roles: $internal, style: TransitionStyle::Primary, description: 'The task is done.'),
                new TransitionDefinition(id: 'reopen', label: 'Reopen', from: ['done'], to: 'todo', roles: $internal, description: 'The task is back to do.'),
            ],
        ),
    ],
);
