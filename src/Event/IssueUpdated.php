<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * Properties of the issue changed (UpdateIssue, or an automation rule), one journal entry each.
 * The new assignee, when the assignment changed to someone, and the
 * rewritten description are named because the subscription rules act on
 * them.
 */
final readonly class IssueUpdated extends IssueEvent
{
    /**
     * @param list<JournalEntry> $entries
     */
    public function __construct(
        Issue $issue,
        ?User $actor,
        array $entries,
        public ?User $newAssignee = null,
        public bool $descriptionChanged = false,
        ?string $rule = null,
    ) {
        parent::__construct($issue, $actor, $entries, $rule);
    }
}
