<?php

declare(strict_types=1);

namespace App\Message\Notification;

/**
 * Tells the subscribers of an issue what a command did to it, by mail.
 * Dispatched by Notification/NotificationDispatcher once the change is
 * committed, carried by the `async` transport and handled by
 * MessageHandler/Notification/NotifyIssueChangeHandler in a worker. Ids
 * only: the handler reads the current state, and what disappeared in the
 * meantime is left out.
 */
final readonly class NotifyIssueChange
{
    /**
     * @param list<int>    $entryIds the journal entries the command wrote
     * @param class-string $event    the domain event, for the wording of the mail
     */
    public function __construct(
        public int $issueId,
        public int $actorId,
        public array $entryIds,
        public ?int $commentId,
        public string $event,
    ) {
    }
}
