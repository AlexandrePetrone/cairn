<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Permissions that are not governed by a workflow. The instance configuration
 * maps each one to the roles that hold it; administrators hold all of them.
 */
enum Permission: string
{
    case IssueCreate = 'issue.create';
    case IssueEdit = 'issue.edit';
    case IssueChangeTracker = 'issue.change_tracker';
    case IssueAssign = 'issue.assign';
    case IssueSetCriticality = 'issue.set_criticality';
    case IssueSetPrivate = 'issue.set_private';
    case IssueLink = 'issue.link';
    case IssueExport = 'issue.export';
    case CommentCreate = 'comment.create';
    case CommentEditOwn = 'comment.edit_own';
    case CommentEditAny = 'comment.edit_any';
    case CommentSetPrivate = 'comment.set_private';
    case TrackerManage = 'tracker.manage';
    case ReleaseManage = 'release.manage';
    case ProjectManage = 'project.manage';
    case ProjectManageMembers = 'project.manage_members';
    case ProjectCreate = 'project.create';
    case WorkspaceManage = 'workspace.manage';
}
