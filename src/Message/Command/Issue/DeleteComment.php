<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

/**
 * Deletes a comment and its files; the journal keeps its text. Handled by
 * DeleteCommentHandler.
 */
final readonly class DeleteComment
{
    public function __construct(
        public int $actorId,
        public int $commentId,
    ) {
    }
}
