<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * A file was added to the issue itself.
 */
final readonly class AttachmentAdded extends IssueEvent
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
