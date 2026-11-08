<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * The body of a comment was rewritten.
 */
final readonly class CommentEdited extends IssueEvent
{
    /**
     * @param list<JournalEntry> $entries
     */
    public function __construct(
        Issue $issue,
        User $actor,
        array $entries,
        public Comment $comment,
    ) {
        parent::__construct($issue, $actor, $entries);
    }

    public function comment(): Comment
    {
        return $this->comment;
    }
}
