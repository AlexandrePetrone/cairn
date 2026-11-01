<?php

declare(strict_types=1);

namespace App\Message\Command\Attachment;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Stores a file, of any type, up to 10 MB. Without an issue it is pending:
 * sent while an issue or a comment is being written, it waits for the
 * command that writes it. With an issue key it goes straight to that
 * issue (its Files block). Handled by UploadAttachmentHandler, which
 * returns the Attachment.
 */
final readonly class UploadAttachment
{
    public function __construct(
        public int $actorId,
        public int $projectId,
        public ?string $issueKey,
        #[Assert\NotNull]
        #[Assert\File(maxSize: '10M')]
        public UploadedFile $file,
    ) {
    }
}
