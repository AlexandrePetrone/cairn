<?php

declare(strict_types=1);

namespace App\Message\Command\Attachment;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Removes a file from an issue or a comment, and deletes it: the journal
 * keeps its name. Handled by RemoveAttachmentHandler.
 */
final readonly class RemoveAttachment
{
    public function __construct(
        public int $actorId,
        #[Assert\Uuid]
        public string $uuid,
    ) {
    }
}
