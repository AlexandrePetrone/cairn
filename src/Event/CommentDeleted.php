<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * A comment was deleted; the journal entry keeps its text.
 */
final readonly class CommentDeleted extends IssueEvent
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
