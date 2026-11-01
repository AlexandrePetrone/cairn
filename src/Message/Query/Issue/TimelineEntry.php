<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\Comment;
use App\Entity\JournalEntry;

/**
 * One moment of the thread of an issue: a journal entry, a comment, or a
 * journal entry with the comment that came with it. `comment` is the one
 * shown at that moment, whichever way it got there.
 */
final readonly class TimelineEntry
{
    private function __construct(
        public \DateTimeImmutable $at,
        public ?JournalEntry $event,
        public ?Comment $comment,
    ) {
    }

    public static function ofEvent(JournalEntry $event): self
    {
        return new self($event->getAt(), $event, $event->getComment());
    }

    public static function ofComment(Comment $comment): self
    {
        return new self($comment->getCreatedAt(), null, $comment);
    }
}
