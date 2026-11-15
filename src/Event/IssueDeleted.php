<?php

declare(strict_types=1);

namespace App\Event;

/**
 * The issue was erased (DeleteIssue): it has no journal any more, so the
 * event carries no entry. Only the real time listens to it, so that the
 * lists and kanbans showing the issue drop it; nobody is notified.
 */
final readonly class IssueDeleted extends IssueEvent
{
}
