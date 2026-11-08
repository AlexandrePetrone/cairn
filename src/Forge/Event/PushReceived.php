<?php

declare(strict_types=1);

namespace App\Forge\Event;

use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * A forge delivered a push of a linked repository, authenticated against
 * that link: the event is bound to it, and through it to one project. A
 * repository linked to several projects yields one event per link.
 */
final class PushReceived extends RemoteEvent
{
    /**
     * @param list<CommitPayload> $commits
     */
    public function __construct(
        string $id,
        public readonly int $codeRepositoryId,
        public readonly string $branch,
        public readonly string $branchUrl,
        public readonly array $commits,
    ) {
        parent::__construct('push', $id, []);
    }
}
