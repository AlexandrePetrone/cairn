<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * An attachment row never outlives its file, nor the file its row: once
 * the row is deleted, so is the file.
 */
#[AsEntityListener(event: Events::postRemove, entity: Attachment::class)]
final class AttachmentFileRemover
{
    public function __construct(
        private readonly AttachmentStorage $storage,
    ) {
    }

    public function postRemove(Attachment $attachment): void
    {
        $this->storage->delete($attachment);
    }
}
