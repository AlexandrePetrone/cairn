<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adds a comment to an issue. Handled by AddCommentHandler, which returns
 * the Comment.
 */
final readonly class AddComment
{
    /**
     * @param list<string> $attachments uuids of the actor's pending files sent with the comment
     */
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $body,
        public bool $private = false,
        public array $attachments = [],
    ) {
    }
}
