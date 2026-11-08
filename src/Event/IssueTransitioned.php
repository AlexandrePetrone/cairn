<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * A workflow action was applied, with or without a comment.
 */
final readonly class IssueTransitioned extends IssueEvent
{
    /**
     * @param list<JournalEntry> $entries
     */
    public function __construct(
        Issue $issue,
        User $actor,
        array $entries,
        public ?Comment $comment = null,
    ) {
        parent::__construct($issue, $actor, $entries);
    }

    public function comment(): ?Comment
    {
        return $this->comment;
    }
}
