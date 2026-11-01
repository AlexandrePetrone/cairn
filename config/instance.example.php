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
        new RoleDefinition(id: 'project_manager', label: 'Chef de projet', internalOnly: true),
        new RoleDefinition(id: 'developer', label: 'Développeur', internalOnly: true),
        new RoleDefinition(id: 'client', label: 'Client'),
        new RoleDefinition(id: 'client_readonly', label: 'Client (lecture)'),
    ],

    // Permissions outside workflows. Administrators hold all of them.
    permissions: [
        Permission::IssueCreate->value => $internalAndClient,
        // Authors always edit their own issues and comments; these two
        // grant editing the content written by others.
        Permission::IssueEdit->value => $managers,
        Permission::IssueChangeTracker->value => $internal,
        Permission::IssueAssign->value => $internal,
        Permission::IssueSetCriticality->value => $internalAndClient,
        Permission::IssueSetPrivate->value => $internal,
        Permission::CommentCreate->value => $internalAndClient,
        Permission::CommentSetPrivate->value => $internal,
        Permission::CommentEdit->value => $managers,
    ],

    // Global fields; each workflow picks the ones it uses.
    fields: [
        new FieldDefinition(
            id: 'environment',
            label: 'Environnement',
            type: FieldType::Select,
            options: ['prod' => 'Production', 'preprod' => 'Préproduction', 'staging' => 'Recette'],
        ),
        new FieldDefinition(
            id: 'url',
            label: 'URL concernée',
            type: FieldType::Url,
            placeholder: 'https://…',
            help: 'L\'url où le problème se produit.',
        ),
        new FieldDefinition(
            id: 'browser',
            label: 'Navigateur / appareil',
            type: FieldType::Text,
            placeholder: 'Chrome 130 sur macOS, Safari iPhone…',
        ),
        new FieldDefinition(
            id: 'reproducible',
            label: 'Reproductible',
            type: FieldType::Choice,
            options: ['always' => 'Oui, systématiquement', 'sometimes' => 'Parfois', 'once' => 'Une seule fois'],
        ),
        new FieldDefinition(
            id: 'goal',
            label: 'Objectif',
            type: FieldType::Textarea,
            placeholder: 'Quel problème cette évolution résout-elle, pour qui ?',
            help: 'Le besoin plutôt que la solution : ça nous aide à proposer la bonne réponse.',
        ),
        new FieldDefinition(
            id: 'due_date',
            label: 'Échéance souhaitée',
            type: FieldType::Date,
        ),
        new FieldDefinition(
            id: 'estimate',
            label: 'Estimation (jours)',
            type: FieldType::Number,
            internalOnly: true,
        ),
    ],

    // Without an inverse label the link reads the same from both sides.
    links: [
        new LinkTypeDefinition(id: 'related', label: 'Lié à'),
        new LinkTypeDefinition(id: 'duplicates', label: 'Doublon de', inverseLabel: 'Dupliqué par'),
        new LinkTypeDefinition(id: 'blocks', label: 'Bloque', inverseLabel: 'Bloqué par'),
    ],

    workflows: [
        new WorkflowDefinition(
            id: 'bug',
            label: 'Anomalie',
            initial: 'new',
            places: [
                new PlaceDefinition(id: 'new', label: 'Nouveau', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'qualified', label: 'Qualifiée', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'in_progress', label: 'En cours', status: PlaceStatus::InProgress),
                new PlaceDefinition(
                    id: 'to_test',
                    label: 'À recetter',
                    status: PlaceStatus::Waiting,
                    hint: 'La correction est en préproduction, le client doit valider ou refuser. Votre commentaire accompagne l\'action.',
                ),
                new PlaceDefinition(id: 'done', label: 'Fermée', status: PlaceStatus::Closed),
                new PlaceDefinition(id: 'rejected', label: 'Rejetée', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'qualify', label: 'Qualifier', from: ['new'], to: 'qualified', roles: $internal),
                new TransitionDefinition(id: 'reject', label: 'Rejeter', from: ['new', 'qualified'], to: 'rejected', roles: $internal, style: TransitionStyle::Danger),
                new TransitionDefinition(id: 'start', label: 'Démarrer', from: ['qualified'], to: 'in_progress', roles: $internal),
                new TransitionDefinition(id: 'deliver', label: 'Livrer en recette', from: ['in_progress'], to: 'to_test', roles: $internal),
                new TransitionDefinition(id: 'accept', label: 'Valider', from: ['to_test'], to: 'done', roles: $clientFacing, style: TransitionStyle::Primary),
                new TransitionDefinition(
                    id: 'refuse',
                    label: 'Refuser',
                    from: ['to_test'],
                    to: 'in_progress',
                    roles: $clientFacing,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'Expliquez ce qui ne va pas : un commentaire est obligatoire pour refuser.',
                ),
                new TransitionDefinition(id: 'reopen', label: 'Rouvrir', from: ['done'], to: 'in_progress', roles: $clientFacing),
                new TransitionDefinition(id: 'reopen_rejected', label: 'Rouvrir', from: ['rejected'], to: 'new', roles: $internal),
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
            label: 'Évolution',
            initial: 'new',
            places: [
                new PlaceDefinition(id: 'new', label: 'Nouveau', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'to_estimate', label: 'À chiffrer', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'planned', label: 'Planifiée', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'in_progress', label: 'En cours', status: PlaceStatus::InProgress),
                new PlaceDefinition(
                    id: 'to_test',
                    label: 'À recetter',
                    status: PlaceStatus::Waiting,
                    hint: 'L\'évolution est en préproduction, le client doit valider ou refuser.',
                ),
                new PlaceDefinition(id: 'delivered', label: 'Livrée', status: PlaceStatus::Closed),
                new PlaceDefinition(id: 'declined', label: 'Abandonnée', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'estimate', label: 'À chiffrer', from: ['new'], to: 'to_estimate', roles: $internal),
                new TransitionDefinition(id: 'decline', label: 'Abandonner', from: ['new', 'to_estimate'], to: 'declined', roles: $internal, style: TransitionStyle::Danger),
                new TransitionDefinition(id: 'plan', label: 'Planifier', from: ['to_estimate'], to: 'planned', roles: $internal, style: TransitionStyle::Primary),
                new TransitionDefinition(id: 'unplan', label: 'Déplanifier', from: ['planned'], to: 'to_estimate', roles: $internal),
                new TransitionDefinition(id: 'start', label: 'Démarrer', from: ['planned'], to: 'in_progress', roles: $internal),
                new TransitionDefinition(id: 'deliver', label: 'Livrer en recette', from: ['in_progress'], to: 'to_test', roles: $internal),
                new TransitionDefinition(id: 'accept', label: 'Valider', from: ['to_test'], to: 'delivered', roles: $clientFacing, style: TransitionStyle::Primary),
                new TransitionDefinition(
                    id: 'refuse',
                    label: 'Refuser',
                    from: ['to_test'],
                    to: 'in_progress',
                    roles: $clientFacing,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'Expliquez ce qui ne correspond pas à la demande : un commentaire est obligatoire pour refuser.',
                ),
                new TransitionDefinition(id: 'reopen', label: 'Rouvrir', from: ['delivered'], to: 'in_progress', roles: $internal),
                new TransitionDefinition(id: 'reopen_declined', label: 'Rouvrir', from: ['declined'], to: 'new', roles: $internal),
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
            label: 'Tâche interne',
            initial: 'todo',
            places: [
                new PlaceDefinition(id: 'todo', label: 'À faire', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'doing', label: 'En cours', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'done', label: 'Faite', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'start', label: 'Démarrer', from: ['todo'], to: 'doing', roles: $internal),
                new TransitionDefinition(id: 'finish', label: 'Terminer', from: ['doing'], to: 'done', roles: $internal, style: TransitionStyle::Primary),
                new TransitionDefinition(id: 'reopen', label: 'Rouvrir', from: ['done'], to: 'todo', roles: $internal),
            ],
        ),
    ],
);
