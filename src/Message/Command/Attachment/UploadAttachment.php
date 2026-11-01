<?php

declare(strict_types=1);

namespace App\Message\Command\Attachment;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Stores a file, of any type, up to 10 MB, sent while an issue or a
 * comment is being written: it is pending, seen by its uploader only,
 * until the command that saves the text claims it. Handled by
 * UploadAttachmentHandler, which returns the Attachment.
 */
final readonly class UploadAttachment
{
    public function __construct(
        public int $actorId,
        public int $projectId,
        #[Assert\NotNull]
        #[Assert\File(maxSize: '10M')]
        public UploadedFile $file,
    ) {
    }
}
