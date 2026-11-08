<?php

declare(strict_types=1);

namespace App\MessageHandler\Notification;

use App\Entity\JournalEntry;
use App\Message\Notification\NotifyIssueChange;
use App\Notification\IssueMailer;
use App\Repository\CommentRepository;
use App\Repository\IssueRepository;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\JournalEntryRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One mail per subscriber of the issue, except the one who acted, with
 * what each may see: an external user gets neither the private journal
 * lines nor a private comment, and no mail at all when nothing is left,
 * or when the issue itself became invisible to them. Following is not a
 * right: AccessControl decides here, at the time of sending.
 */
#[AsMessageHandler(bus: 'messenger.bus.default')]
final class NotifyIssueChangeHandler
{
    public function __construct(
        private readonly IssueRepository $issues,
        private readonly UserRepository $users,
        private readonly JournalEntryRepository $journal,
        private readonly CommentRepository $comments,
        private readonly IssueSubscriptionRepository $subscriptions,
        private readonly AccessControl $access,
        private readonly IssueMailer $mailer,
    ) {
    }

    public function __invoke(NotifyIssueChange $message): void
    {
        $issue = $this->issues->find($message->issueId);
        $actor = $this->users->find($message->actorId);
        if (null === $issue || null === $actor) {
            return;
        }
        $entries = [] === $message->entryIds ? [] : $this->journal->findBy(['id' => $message->entryIds], ['id' => 'ASC']);
        $comment = null === $message->commentId ? null : $this->comments->find($message->commentId);

        foreach ($this->subscriptions->findSubscribers($issue) as $recipient) {
            if ($recipient->getId() === $actor->getId() || !$this->access->canSee($recipient, $issue)) {
                continue;
            }
            $visibleEntries = array_values(array_filter($entries, static fn (JournalEntry $entry): bool => !$entry->isPrivate() || $recipient->isInternal()));
            $visibleComment = null !== $comment && $this->access->canSeeComment($recipient, $comment) ? $comment : null;
            if ([] === $visibleEntries && null === $visibleComment) {
                continue;
            }
            $this->mailer->send($recipient, $issue, $actor, $visibleEntries, $visibleComment, $message->event);
        }
    }
}
