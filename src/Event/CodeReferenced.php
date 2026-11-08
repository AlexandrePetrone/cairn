<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Issue;
use App\Entity\JournalEntry;

/**
 * A forge delivered commits or a merge request mentioning the issue: new
 * references were written, or a merge request changed state. No actor,
 * the forge acted. Nobody listens to it for notifications or
 * subscriptions, on purpose: the code is the agency's business.
 */
final readonly class CodeReferenced extends IssueEvent
{
    /**
     * @param list<JournalEntry> $entries
     */
    public function __construct(Issue $issue, array $entries)
    {
        parent::__construct($issue, null, $entries);
    }
}
