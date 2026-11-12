<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Entity\Enum\JournalEntryType;
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
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tells the pages that show a project that one of its issues changed, so
 * that lists and kanbans follow what others do. What it publishes is a
 * signal, never content: the id of the issue, its tracker, the place it
 * stands at and, when it just moved, the place it left. A page that hears it reads again what it shows,
 * through the queries it was drawn with, so visibility, filters and rights
 * stay where they are written.
 *
 * Even a signal says something, so it has two audiences
 * (ProjectTopics): internal users hear every change, everyone else only
 * those an external user may see: an issue that is not private, changed
 * by something else than a private comment or private lines of journal. An issue that just left
 * their sight (made private, moved to another tracker) is named once
 * more, alone, so that it leaves their screen.
 *
 * Like NotificationDispatcher, the events wait for Doctrine's postFlush
 * and the update leaves after the command's transaction committed. It
 * travels on `async`: the worker publishes, so a hub that is slow or down
 * never holds a request.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class IssueBroadcaster implements ResetInterface
{
    /** @var list<IssueEvent> */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ProjectTopics $topics,
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
        if ($this->topics->enabled()) {
            $this->pending[] = $event;
        }
    }

    public function postFlush(): void
    {
        $events = $this->pending;
        $this->pending = [];
        foreach ($events as $event) {
            $issue = $event->issue;
            if (null === $issue->getId()) {
                continue;
            }
            $project = $issue->getProject();
            $change = [
                'issue' => $issue->getId(),
                'tracker' => $issue->getTracker()->getId(),
                'place' => $issue->getPlace(),
                'from' => self::placeLeft($event),
            ];
            $internal = $this->topics->internal($project);
            $everyone = $this->topics->everyone($project);
            if ($issue->isPrivate()) {
                $this->publish([$internal], $change);
                if (array_any($event->entries, self::hides(...))) {
                    $this->publish([$everyone], ['issue' => $issue->getId()]);
                }
            } else {
                $this->publish(self::confidential($event) ? [$internal] : [$internal, $everyone], $change);
            }
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    /**
     * The place the issue left, when the change moved it: the column a
     * kanban must count again, even when it does not show the card.
     */
    private static function placeLeft(IssueEvent $event): ?string
    {
        foreach ($event->entries as $entry) {
            if (\in_array($entry->getType(), [JournalEntryType::Transitioned, JournalEntryType::Moved, JournalEntryType::TrackerChanged], true)) {
                return $entry->text('from_id');
            }
        }

        return null;
    }

    /**
     * Whether external users see nothing of the change: all it carries,
     * journal lines and comment, is private. A transition is never so: its
     * private comment hides the comment and the line, not the place the
     * issue went to.
     */
    private static function confidential(IssueEvent $event): bool
    {
        if ($event instanceof IssueTransitioned) {
            return false;
        }
        $comment = $event->comment();
        $private = array_map(static fn (JournalEntry $entry): bool => $entry->isPrivate(), $event->entries);
        if (null !== $comment) {
            $private[] = $comment->isPrivate();
        }

        return [] !== $private && !\in_array(false, $private, true);
    }

    /**
     * Whether the entry may be what took the issue out of the sight of
     * external users: its visibility changed, or its tracker did.
     */
    private static function hides(JournalEntry $entry): bool
    {
        return JournalEntryType::TrackerChanged === $entry->getType()
            || (JournalEntryType::Changed === $entry->getType() && 'visibility' === $entry->text('field'));
    }

    /**
     * @param list<string>                   $topics
     * @param array<string, int|string|null> $change
     */
    private function publish(array $topics, array $change): void
    {
        $this->bus->dispatch(
            new Update($topics, json_encode($change, \JSON_THROW_ON_ERROR), private: true),
            [new DispatchAfterCurrentBusStamp()],
        );
    }
}
