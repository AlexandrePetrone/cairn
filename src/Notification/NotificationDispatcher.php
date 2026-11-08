<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\JournalEntry;
use App\Event\AttachmentAdded;
use App\Event\AttachmentRemoved;
use App\Event\CommentAdded;
use App\Event\CommentDeleted;
use App\Event\CommentEdited;
use App\Event\IssueCreated;
use App\Event\IssueEvent;
use App\Event\IssueMoved;
use App\Event\IssuesLinked;
use App\Event\IssuesUnlinked;
use App\Event\IssueTransitioned;
use App\Event\IssueUpdated;
use App\Message\Notification\NotifyIssueChange;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns the domain events that deserve a mail into NotifyIssueChange
 * messages. The listed events are the whole policy: an event this class
 * does not listen to notifies nobody. The events arrive inside the
 * transaction, before anything has an id, so they wait here until
 * Doctrine's postFlush; the messages then leave with
 * DispatchAfterCurrentBusStamp, after the command's transaction has
 * committed, and are dropped with it when it fails.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class NotificationDispatcher implements ResetInterface
{
    /** @var list<IssueEvent> */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener(event: IssueCreated::class)]
    #[AsEventListener(event: IssueUpdated::class)]
    #[AsEventListener(event: IssueTransitioned::class)]
    #[AsEventListener(event: IssueMoved::class)]
    #[AsEventListener(event: CommentAdded::class)]
    #[AsEventListener(event: CommentEdited::class)]
    #[AsEventListener(event: CommentDeleted::class)]
    #[AsEventListener(event: AttachmentAdded::class)]
    #[AsEventListener(event: AttachmentRemoved::class)]
    #[AsEventListener(event: IssuesLinked::class)]
    #[AsEventListener(event: IssuesUnlinked::class)]
    public function collect(IssueEvent $event): void
    {
        $this->pending[] = $event;
    }

    public function postFlush(): void
    {
        $events = $this->pending;
        $this->pending = [];
        foreach ($events as $event) {
            $issueId = $event->issue->getId();
            $actorId = $event->actor->getId();
            if (null === $issueId || null === $actorId) {
                continue;
            }
            $entryIds = array_values(array_filter(array_map(static fn (JournalEntry $entry): ?int => $entry->getId(), $event->entries)));
            $this->bus->dispatch(
                new NotifyIssueChange($issueId, $actorId, $entryIds, $event->comment()?->getId(), $event::class),
                [new DispatchAfterCurrentBusStamp()],
            );
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
