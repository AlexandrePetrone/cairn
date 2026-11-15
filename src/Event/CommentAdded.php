<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * A comment was written on its own: no journal entry, the comment is the change.
 */
final readonly class CommentAdded extends IssueEvent
{
    /**
     * @param list<JournalEntry> $entries
     */
    public function __construct(
        Issue $issue,
        ?User $actor,
        array $entries,
        public Comment $comment,
        ?string $rule = null,
    ) {
        parent::__construct($issue, $actor, $entries, $rule);
    }

    public function comment(): Comment
    {
        return $this->comment;
    }
}
