<?php

declare(strict_types=1);

namespace App\Message\Command\Attachment;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adds a file, of any type, up to 10 MB, to an issue that exists: its
 * Files block. Journaled. Handled by AddAttachmentHandler, which returns
 * the Attachment.
 */
final readonly class AddAttachment
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        #[Assert\NotNull]
        #[Assert\File(maxSize: '10M')]
        public UploadedFile $file,
    ) {
    }
}
