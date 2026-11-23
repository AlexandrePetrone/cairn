<?php

declare(strict_types=1);

namespace App\Security;

/**
 * What a role may do besides moving issues: the instance configuration maps
 * each permission to the roles that hold it; administrators hold all of
 * them. How a role moves issues is its own (RoleDefinition::$freeWorkflow).
 * A case is added with the command that enforces it.
 */
enum Permission: string
{
    case IssueCreate = 'issue.create';
    /** Edit the content of issues written by others; authors always edit their own. */
    case IssueEdit = 'issue.edit';
    /** Delete an issue for good, with its comments, files, links and history. */
    case IssueDelete = 'issue.delete';
    case IssueSetPrivate = 'issue.set_private';
    case IssueAssign = 'issue.assign';
    case IssueSetCriticality = 'issue.set_criticality';
    /** Schedule an issue in an open release of its project, or take it out. */
    case IssueSetRelease = 'issue.set_release';
    /** Put the tags of the project on an issue, or take them off. */
    case IssueSetTags = 'issue.set_tags';
    /** Link an issue to another one, or remove a link; across projects when held on both. */
    case IssueLink = 'issue.link';
    /** Arrange the cards of a kanban column by hand; the order is everyone's. */
    case IssueRank = 'issue.rank';
    case CommentCreate = 'comment.create';
    /** Edit or delete the comments of others; authors always handle their own. */
    case CommentEdit = 'comment.edit';
    case CommentSetPrivate = 'comment.set_private';
    /** Change the project's name and description, create and arrange its trackers, link its repositories, write its automation rules. */
    case ProjectConfigure = 'project.configure';
    /** Grant, change and revoke the roles held directly on the project. */
    case ProjectManageMembers = 'project.manage_members';
    /** Create the releases of the project, change, deliver and abandon them. */
    case ReleaseManage = 'release.manage';
    /** Read the budgets of the project and their lines, and those of an issue on its page. */
    case BudgetView = 'budget.view';
    /** Create and close the budgets of the project, delete a closed one, write, change and delete their lines. */
    case BudgetManage = 'budget.manage';

    /**
     * Where the page that grants the permissions shows this one.
     */
    public function group(): PermissionGroup
    {
        return match ($this) {
            self::IssueCreate, self::IssueEdit, self::IssueDelete, self::IssueSetPrivate => PermissionGroup::Issues,
            self::IssueAssign, self::IssueSetCriticality, self::IssueSetRelease, self::IssueSetTags, self::IssueLink, self::IssueRank => PermissionGroup::Tracking,
            self::CommentCreate, self::CommentEdit, self::CommentSetPrivate => PermissionGroup::Comments,
            self::ProjectConfigure, self::ProjectManageMembers, self::ReleaseManage => PermissionGroup::Project,
            self::BudgetView, self::BudgetManage => PermissionGroup::Budgets,
        };
    }

    /**
     * Whether using the permission takes seeing what external users never
     * see: the project configuration shows private trackers, managing
     * members shows every account of the instance, and what is made
     * private is hidden from an external user who made it so. That is the
     * one reason: what is only risky (deleting an issue) is for the
     * administrators to grant as they see fit. The configuration grants
     * these to internal-only roles, which ConfigValidator enforces.
     */
    public function internalOnly(): bool
    {
        return match ($this) {
            self::ProjectConfigure, self::ProjectManageMembers, self::IssueSetPrivate, self::CommentSetPrivate => true,
            default => false,
        };
    }
}
