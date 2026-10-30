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
    case IssueEdit = 'issue.edit';
    case IssueChangeTracker = 'issue.change_tracker';
    case IssueAssign = 'issue.assign';
    case IssueSetCriticality = 'issue.set_criticality';
    case IssueSetPrivate = 'issue.set_private';
    case CommentCreate = 'comment.create';
    case CommentSetPrivate = 'comment.set_private';
}
