<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

/**
 * Removes a link between two issues; the journal of both keeps it. The
 * actor needs `issue.link` on the project of one of them and must see
 * both. Handled by RemoveLinkHandler.
 */
final readonly class RemoveLink
{
    public function __construct(
        public int $actorId,
        public int $linkId,
    ) {
    }
}
