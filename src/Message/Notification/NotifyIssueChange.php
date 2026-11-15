<?php

declare(strict_types=1);

namespace App\Message\Notification;

/**
 * Tells the subscribers of an issue what a command did to it.
 * Dispatched by Notification/NotificationDispatcher once the change is
 * committed, carried by the `async` transport and handled by
 * MessageHandler/Notification/NotifyIssueChangeHandler in a worker, which
 * queues it for each of them. Ids only: the current state is read again
 * when the mail is built, and what disappeared in the meantime is left
 * out.
 */
final readonly class NotifyIssueChange
{
    /**
     * @param int|null    $actorId  who acted; null when an automation rule did
     * @param list<int>   $entryIds the journal entries the command wrote
     * @param string|null $rule     the name of the rule that acted, when one did
     */
    public function __construct(
        public int $issueId,
        public ?int $actorId,
        public array $entryIds,
        public ?int $commentId,
        public ?string $rule = null,
    ) {
    }
}
