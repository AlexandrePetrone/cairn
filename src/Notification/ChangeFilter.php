<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\JournalEntryRepository;
use App\Security\AccessControl;

/**
 * What one person may read of a change made to an issue: an external user
 * gets neither the private journal lines nor a private comment. A change
 * travels as ids (the message, the queue): load() reads it again, without
 * what was deleted since, and visible() cuts it for one reader. Asked
 * twice, when the change is queued (so that what a person cannot read
 * never delays their mail) and when the mail is built, since following is
 * not a right and rights move in between.
 */
final class ChangeFilter
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly JournalEntryRepository $journal,
        private readonly CommentRepository $comments,
    ) {
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array{list<JournalEntry>, ?Comment} the journal lines, oldest first, and the comment, when they still exist
     */
    public function load(array $entryIds, ?int $commentId): array
    {
        return [
            [] === $entryIds ? [] : array_values($this->journal->findBy(['id' => $entryIds], ['id' => 'ASC'])),
            null === $commentId ? null : $this->comments->find($commentId),
        ];
    }

    /**
     * @param list<JournalEntry> $entries
     *
     * @return array{list<JournalEntry>, ?Comment}|null null when nothing is left, or the issue itself is out of sight
     */
    public function visible(User $recipient, Issue $issue, array $entries, ?Comment $comment): ?array
    {
        if (!$this->access->canSee($recipient, $issue)) {
            return null;
        }
        $visibleEntries = array_values(array_filter($entries, static fn (JournalEntry $entry): bool => !$entry->isPrivate() || $recipient->isInternal()));
        $visibleComment = null !== $comment && $this->access->canSeeComment($recipient, $comment) ? $comment : null;
        if ([] === $visibleEntries && null === $visibleComment) {
            return null;
        }

        return [$visibleEntries, $visibleComment];
    }
}
