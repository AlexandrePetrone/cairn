<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * A link to another issue was removed; the other issue gets its own event.
 */
final readonly class IssuesUnlinked extends IssueEvent
{
    /**
     * @param list<JournalEntry> $entries
     */
    public function __construct(
        Issue $issue,
        User $actor,
        array $entries,
    ) {
        parent::__construct($issue, $actor, $entries);
    }
}
