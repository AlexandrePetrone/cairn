<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Permissions that are not governed by a workflow. The instance configuration
 * maps each one to the roles that hold it; administrators hold all of them.
 * A case is added with the command that enforces it.
 */
enum Permission: string
{
    case IssueCreate = 'issue.create';
    /** Edit the content of issues written by others; authors always edit their own. */
    case IssueEdit = 'issue.edit';
    case IssueChangeTracker = 'issue.change_tracker';
    case IssueAssign = 'issue.assign';
    case IssueSetCriticality = 'issue.set_criticality';
    case IssueSetPrivate = 'issue.set_private';
    case CommentCreate = 'comment.create';
    case CommentSetPrivate = 'comment.set_private';
    /** Edit or delete the comments of others; authors always handle their own. */
    case CommentEdit = 'comment.edit';
    /** Change the project's name and description, create and arrange its trackers. */
    case ProjectConfigure = 'project.configure';
    /** Grant, change and revoke the roles held directly on the project. */
    case ProjectManageMembers = 'project.manage_members';

    /**
     * Whether the permission opens what external users never see: the
     * project configuration shows private trackers and their private
     * issues, managing members shows every account of the instance. The
     * configuration grants these to internal-only roles, which
     * ConfigValidator enforces.
     */
    public function internalOnly(): bool
    {
        return match ($this) {
            self::ProjectConfigure, self::ProjectManageMembers => true,
            default => false,
        };
    }
}
