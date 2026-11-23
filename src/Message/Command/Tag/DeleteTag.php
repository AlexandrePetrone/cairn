<?php

declare(strict_types=1);

namespace App\Message\Command\Tag;

/**
 * Deletes a tag, whatever carries it: its issues lose it, without a word
 * in their history nor a mail. The actor needs `project.configure`.
 * Handled by DeleteTagHandler.
 */
final readonly class DeleteTag
{
    public function __construct(
        public int $actorId,
        public int $tagId,
    ) {
    }
}
