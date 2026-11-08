<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * An issue was created; its description may mention people.
 */
final readonly class IssueCreated extends IssueEvent
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
