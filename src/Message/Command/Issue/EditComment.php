<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rewrites a comment. Both versions go to the journal. Handled by
 * EditCommentHandler.
 */
final readonly class EditComment
{
    /**
     * @param list<string> $attachments uuids of the actor's pending files sent with the new version
     */
    public function __construct(
        public int $actorId,
        public int $commentId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $body,
        public array $attachments = [],
    ) {
    }
}
