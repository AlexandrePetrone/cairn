<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * Something happened to an issue. Dispatched by the command handler inside
 * the transaction, once per command, after the journal entries it wrote
 * were persisted (IssueHandlerSupport::record()): the entries say what
 * changed with the labels of the time, the subclass says what kind of
 * change it was. Nothing has an id yet; a listener that needs ids waits
 * for Doctrine's postFlush (Notification/NotificationDispatcher).
 */
abstract readonly class IssueEvent
{
    /**
     * @param User|null          $actor   who acted; null when a forge did (CodeReferenced)
     * @param list<JournalEntry> $entries the lines the command wrote on this issue
     */
    public function __construct(
        public Issue $issue,
        public ?User $actor,
        public array $entries,
    ) {
    }

    /**
     * The comment that came with the change, when there is one and it
     * still exists: what the notification shows beside the journal lines.
     */
    public function comment(): ?Comment
    {
        return null;
    }
}
