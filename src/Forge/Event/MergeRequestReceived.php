<?php

declare(strict_types=1);

namespace App\Forge\Event;

use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * A forge delivered a merge request of a linked repository, authenticated
 * and bound to that link as PushReceived is.
 */
final class MergeRequestReceived extends RemoteEvent
{
    public function __construct(
        string $id,
        public readonly int $codeRepositoryId,
        public readonly MergeRequestPayload $mergeRequest,
    ) {
        parent::__construct('merge_request', $id, []);
    }
}
