<?php

declare(strict_types=1);

/*
 * Example instance configuration, in French: the same instance as
 * instance.example.php, for an instance run with CAIRN_LOCALE=fr. Labels
 * are content, written in the language of the instance.
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
        new RoleDefinition(id: 'project_manager', label: 'Chef de projet', internalOnly: true, followsNewIssues: true),
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
        // Put an issue on any step, even one no action leads to: the agency
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
    ],

    // Global fields; each workflow picks the ones it uses. Estimates and
    // quotes are handled outside this tool: no amount is stored here.
    fields: [
        new FieldDefinition(
            id: 'environment',
            label: 'Environnement',
            type: FieldType::Choice,
            options: ['prod' => 'Production', 'preprod' => 'Préproduction'],
        ),
        new FieldDefinition(
            id: 'url',
            label: 'URL concernée',
            type: FieldType::Url,
            placeholder: 'https://…',
            help: 'L\'URL où le problème se produit.',
        ),
        new FieldDefinition(
            id: 'steps',
            label: 'Étapes pour reproduire',
            type: FieldType::Textarea,
            placeholder: '1. Je vais sur… 2. Je clique sur… 3. Je constate…',
            help: 'Ce que vous avez fait, ce que vous attendiez, ce qui s\'est passé.',
        ),
        new FieldDefinition(
            id: 'browser',
            label: 'Contexte',
            type: FieldType::Text,
            placeholder: 'Chrome 130 sur macOS, Safari iPhone, compte utilisé…',
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
                new PlaceDefinition(id: 'new', label: 'Nouvelle', status: PlaceStatus::Open),
                // Waiting on the client: the agency cannot qualify without more details.
                new PlaceDefinition(id: 'needs_info', label: 'Info demandée', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'qualified', label: 'Qualifiée', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'in_progress', label: 'En cours', status: PlaceStatus::InProgress),
                // Waiting on a third party or a prerequisite: the agency holds the issue.
                new PlaceDefinition(id: 'blocked', label: 'Bloquée', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'to_test', label: 'À recetter', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'to_release', label: 'À mettre en production', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'done', label: 'Fermée', status: PlaceStatus::Closed),
                new PlaceDefinition(id: 'rejected', label: 'Rejetée', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(
                    id: 'ask_info',
                    label: 'Demander des précisions',
                    from: ['new'],
                    to: 'needs_info',
                    roles: $internal,
                    requiresComment: true,
                    commentPrompt: 'Précisez ce qu\'il nous manque pour reproduire le problème.',
                    description: 'Il manque des éléments pour comprendre l\'anomalie : la demande attend la réponse du client.',
                ),
                new TransitionDefinition(id: 'provide_info', label: 'Répondre', from: ['needs_info'], to: 'new', roles: $internalAndClient, style: TransitionStyle::Primary, description: 'Les précisions sont apportées : la demande revient à l\'équipe.'),
                new TransitionDefinition(id: 'qualify', label: 'Qualifier', from: ['new'], to: 'qualified', roles: $internal, description: 'L\'anomalie est comprise et reproduite : elle entre dans la file de l\'équipe.'),
                new TransitionDefinition(id: 'reject', label: 'Rejeter', from: ['new', 'needs_info', 'qualified'], to: 'rejected', roles: $internal, style: TransitionStyle::Danger, description: 'Ce n\'est pas une anomalie, ou elle ne sera pas corrigée : la demande est fermée.'),
                new TransitionDefinition(id: 'start', label: 'Démarrer', from: ['new', 'qualified'], to: 'in_progress', roles: $internal, description: 'La correction commence.'),
                new TransitionDefinition(
                    id: 'block',
                    label: 'Bloquer',
                    from: ['in_progress'],
                    to: 'blocked',
                    roles: $internal,
                    requiresComment: true,
                    commentPrompt: 'Indiquez ce qui bloque et qui doit le lever.',
                    description: 'La correction ne peut pas avancer : elle dépend d\'un tiers ou d\'un prérequis.',
                ),
                new TransitionDefinition(id: 'resume', label: 'Reprendre', from: ['blocked'], to: 'in_progress', roles: $internal, description: 'Le blocage est levé : la correction reprend.'),
                new TransitionDefinition(id: 'deliver', label: 'Livrer en recette', from: ['in_progress'], to: 'to_test', roles: $internal, description: 'La correction est en préproduction, prête à être vérifiée.'),
                new TransitionDefinition(id: 'accept', label: 'Valider', from: ['to_test'], to: 'to_release', roles: $clientFacing, style: TransitionStyle::Primary, description: 'La correction est acceptée : elle attend sa mise en production.'),
                new TransitionDefinition(
                    id: 'refuse',
                    label: 'Refuser',
                    from: ['to_test'],
                    to: 'in_progress',
                    roles: $clientFacing,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'Expliquez ce qui ne va pas : un commentaire est obligatoire pour refuser.',
                    description: 'La correction ne convient pas : la demande repart en développement, avec le commentaire qui dit pourquoi.',
                ),
                new TransitionDefinition(id: 'release', label: 'Mettre en production', from: ['to_release'], to: 'done', roles: $internal, style: TransitionStyle::Primary, description: 'La correction est en production : la demande est fermée. Elle pourra être rouverte si le problème revient.'),
                new TransitionDefinition(id: 'reopen', label: 'Rouvrir', from: ['done'], to: 'in_progress', roles: $clientFacing, description: 'Le problème est revenu : la demande repart en développement.'),
                new TransitionDefinition(id: 'reopen_rejected', label: 'Rouvrir', from: ['rejected'], to: 'new', roles: $internal, description: 'La demande est réexaminée depuis le début.'),
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
            label: 'Évolution',
            initial: 'new',
            places: [
                new PlaceDefinition(id: 'new', label: 'Nouvelle', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'to_estimate', label: 'À chiffrer', status: PlaceStatus::Open),
                // Waiting on the client: the estimate was sent, outside this tool.
                new PlaceDefinition(id: 'to_approve', label: 'Chiffrage à valider', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'planned', label: 'Planifiée', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'in_progress', label: 'En cours', status: PlaceStatus::InProgress),
                // Waiting on a third party or a prerequisite: the agency holds the issue.
                new PlaceDefinition(id: 'blocked', label: 'Bloquée', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'to_test', label: 'À recetter', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'to_release', label: 'À mettre en production', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'delivered', label: 'Livrée', status: PlaceStatus::Closed),
                new PlaceDefinition(id: 'declined', label: 'Abandonnée', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'estimate', label: 'À chiffrer', from: ['new'], to: 'to_estimate', roles: $internal, description: 'La demande est comprise : elle passe au chiffrage.'),
                new TransitionDefinition(id: 'decline', label: 'Abandonner', from: ['new', 'to_estimate'], to: 'declined', roles: $internal, style: TransitionStyle::Danger, description: 'L\'évolution ne sera pas réalisée : la demande est fermée.'),
                new TransitionDefinition(id: 'submit_estimate', label: 'Soumettre le chiffrage', from: ['to_estimate'], to: 'to_approve', roles: $internal, description: 'Le chiffrage a été transmis au client : la demande attend sa décision.'),
                new TransitionDefinition(id: 'approve', label: 'Accepter le chiffrage', from: ['to_approve'], to: 'planned', roles: $clientFacing, style: TransitionStyle::Primary, description: 'Le chiffrage est accepté : l\'évolution est planifiée.'),
                new TransitionDefinition(
                    id: 'revise',
                    label: 'Demander une révision',
                    from: ['to_approve'],
                    to: 'to_estimate',
                    roles: $clientFacing,
                    requiresComment: true,
                    commentPrompt: 'Précisez ce qui doit être revu dans le chiffrage ou le périmètre.',
                    description: 'Le chiffrage est à revoir : la demande retourne à chiffrer.',
                ),
                new TransitionDefinition(
                    id: 'decline_estimate',
                    label: 'Refuser le chiffrage',
                    from: ['to_approve'],
                    to: 'declined',
                    roles: $clientFacing,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'Un commentaire est obligatoire pour refuser le chiffrage.',
                    description: 'Le chiffrage est refusé : l\'évolution ne sera pas réalisée et la demande est fermée.',
                ),
                new TransitionDefinition(id: 'unplan', label: 'Déplanifier', from: ['planned'], to: 'to_estimate', roles: $internal, description: 'Le planning est remis en question : l\'évolution retourne à chiffrer.'),
                new TransitionDefinition(id: 'start', label: 'Démarrer', from: ['planned'], to: 'in_progress', roles: $internal, description: 'La réalisation commence.'),
                new TransitionDefinition(
                    id: 'block',
                    label: 'Bloquer',
                    from: ['planned', 'in_progress'],
                    to: 'blocked',
                    roles: $internal,
                    requiresComment: true,
                    commentPrompt: 'Indiquez ce qui bloque et qui doit le lever.',
                    description: 'L\'évolution ne peut pas avancer : elle dépend d\'un tiers ou d\'un prérequis.',
                ),
                new TransitionDefinition(id: 'resume', label: 'Reprendre', from: ['blocked'], to: 'in_progress', roles: $internal, description: 'Le blocage est levé : la réalisation reprend.'),
                new TransitionDefinition(id: 'replan', label: 'Replanifier', from: ['blocked'], to: 'planned', roles: $internal, description: 'Le blocage est levé : l\'évolution retourne dans la file de l\'équipe.'),
                new TransitionDefinition(id: 'deliver', label: 'Livrer en recette', from: ['in_progress'], to: 'to_test', roles: $internal, description: 'L\'évolution est en préproduction, prête à être vérifiée.'),
                new TransitionDefinition(id: 'accept', label: 'Valider', from: ['to_test'], to: 'to_release', roles: $clientFacing, style: TransitionStyle::Primary, description: 'L\'évolution correspond à la demande : elle attend sa mise en production.'),
                new TransitionDefinition(
                    id: 'refuse',
                    label: 'Refuser',
                    from: ['to_test'],
                    to: 'in_progress',
                    roles: $clientFacing,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'Expliquez ce qui ne correspond pas à la demande : un commentaire est obligatoire pour refuser.',
                    description: 'L\'évolution ne correspond pas à la demande : elle repart en développement, avec le commentaire qui dit pourquoi.',
                ),
                new TransitionDefinition(id: 'release', label: 'Mettre en production', from: ['to_release'], to: 'delivered', roles: $internal, style: TransitionStyle::Primary, description: 'L\'évolution est en production : la demande est fermée.'),
                new TransitionDefinition(id: 'reopen', label: 'Rouvrir', from: ['delivered'], to: 'in_progress', roles: $internal, description: 'L\'évolution livrée demande une reprise.'),
                new TransitionDefinition(id: 'reopen_declined', label: 'Rouvrir', from: ['declined'], to: 'new', roles: $internal, description: 'La demande est réexaminée depuis le début.'),
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
            label: 'Tâche interne',
            initial: 'todo',
            places: [
                new PlaceDefinition(id: 'todo', label: 'À faire', status: PlaceStatus::Open),
                new PlaceDefinition(id: 'doing', label: 'En cours', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'blocked', label: 'Bloquée', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'to_test', label: 'À recetter', status: PlaceStatus::Waiting),
                new PlaceDefinition(id: 'to_release', label: 'À mettre en production', status: PlaceStatus::InProgress),
                new PlaceDefinition(id: 'done', label: 'Faite', status: PlaceStatus::Closed),
                new PlaceDefinition(id: 'cancelled', label: 'Annulée', status: PlaceStatus::Closed),
            ],
            transitions: [
                new TransitionDefinition(id: 'start', label: 'Démarrer', from: ['todo'], to: 'doing', roles: $internal, description: 'La tâche est prise en charge.'),
                new TransitionDefinition(
                    id: 'block',
                    label: 'Bloquer',
                    from: ['doing'],
                    to: 'blocked',
                    roles: $internal,
                    requiresComment: true,
                    commentPrompt: 'Indiquez ce qui bloque et qui doit le lever.',
                    description: 'La tâche ne peut pas avancer : elle dépend d\'un tiers ou d\'un prérequis.',
                ),
                new TransitionDefinition(id: 'resume', label: 'Reprendre', from: ['blocked'], to: 'doing', roles: $internal, description: 'Le blocage est levé : la tâche reprend.'),
                new TransitionDefinition(id: 'deliver', label: 'Livrer en recette', from: ['doing'], to: 'to_test', roles: $internal, description: 'La tâche est en préproduction, prête à être vérifiée par l\'équipe.'),
                new TransitionDefinition(id: 'accept', label: 'Valider', from: ['to_test'], to: 'to_release', roles: $internal, style: TransitionStyle::Primary, description: 'La recette interne est bonne : la tâche attend sa mise en production.'),
                new TransitionDefinition(
                    id: 'refuse',
                    label: 'Refuser',
                    from: ['to_test'],
                    to: 'doing',
                    roles: $internal,
                    style: TransitionStyle::Danger,
                    requiresComment: true,
                    commentPrompt: 'Expliquez ce qui ne va pas : un commentaire est obligatoire pour refuser.',
                    description: 'La recette interne échoue : la tâche repart en cours.',
                ),
                new TransitionDefinition(id: 'release', label: 'Mettre en production', from: ['to_release'], to: 'done', roles: $internal, style: TransitionStyle::Primary, description: 'La tâche est en production : elle est faite.'),
                new TransitionDefinition(id: 'cancel', label: 'Annuler', from: ['todo', 'doing', 'blocked'], to: 'cancelled', roles: $internal, style: TransitionStyle::Danger, description: 'La tâche ne sera pas réalisée.'),
                new TransitionDefinition(id: 'reopen', label: 'Rouvrir', from: ['done'], to: 'todo', roles: $internal, description: 'La tâche repart à faire.'),
                new TransitionDefinition(id: 'reopen_cancelled', label: 'Rouvrir', from: ['cancelled'], to: 'todo', roles: $internal, description: 'La tâche est de nouveau à faire.'),
            ],
            fields: [
                new FieldUsage('due_date'),
            ],
        ),
    ],
);
