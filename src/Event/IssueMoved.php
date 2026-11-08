<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * The issue was moved outside its workflow, within its tracker or to another.
 */
final readonly class IssueMoved extends IssueEvent
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
