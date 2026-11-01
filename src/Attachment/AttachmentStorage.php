<?php

declare(strict_types=1);

namespace App\Attachment;

use App\Entity\Attachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where attachment files live: `ATTACHMENTS_DIR`, outside `public/`, one
 * file per attachment named by its uuid, without extension. Nothing else
 * knows the layout.
 */
final class AttachmentStorage
{
    public function __construct(
        #[Autowire('%env(resolve:ATTACHMENTS_DIR)%')]
        private readonly string $directory,
    ) {
    }

    public function store(UploadedFile $file, Attachment $attachment): void
    {
        $file->move($this->directory, $this->name($attachment));
    }

    public function delete(Attachment $attachment): void
    {
        $path = $this->path($attachment);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function path(Attachment $attachment): string
    {
        return $this->directory.'/'.$this->name($attachment);
    }

    private function name(Attachment $attachment): string
    {
        return $attachment->getUuid()->toRfc4122();
    }
}
