<?php

declare(strict_types=1);

namespace App\Message\Command\Attachment;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Stores an image pasted in an editor. Without an issue (a new issue being
 * written) it waits in the project until CreateIssue claims it. Handled by
 * UploadAttachmentHandler, which returns the Attachment.
 */
final readonly class UploadAttachment
{
    public const array MEDIA_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        public int $actorId,
        public int $projectId,
        public ?string $issueKey,
        #[Assert\NotNull]
        #[Assert\Image(maxSize: '10M', mimeTypes: self::MEDIA_TYPES)]
        public UploadedFile $file,
    ) {
    }
}
