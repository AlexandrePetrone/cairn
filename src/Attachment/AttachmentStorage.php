<?php

declare(strict_types=1);

namespace App\Attachment;

use App\Entity\Attachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Uid\Uuid;

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

    /**
     * Moves the file in: one a user sent, or one an import took out of its archive.
     */
    public function store(File $file, Attachment $attachment): void
    {
        $file->move($this->directory, $this->name($attachment));
    }

    public function delete(Attachment $attachment): void
    {
        $this->remove($attachment->getUuid());
    }

    /**
     * The file of an attachment whose row is already gone.
     */
    public function remove(Uuid $uuid): void
    {
        $path = $this->pathOf($uuid);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function path(Attachment $attachment): string
    {
        return $this->pathOf($attachment->getUuid());
    }

    public function pathOf(Uuid $uuid): string
    {
        return $this->directory.'/'.$uuid->toRfc4122();
    }

    /**
     * The uuids of the files present, whatever rows exist for them.
     *
     * @return list<Uuid>
     */
    public function storedUuids(): array
    {
        $uuids = [];
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            $name = basename($path);
            if (Uuid::isValid($name)) {
                $uuids[] = Uuid::fromString($name);
            }
        }

        return $uuids;
    }

    private function name(Attachment $attachment): string
    {
        return $attachment->getUuid()->toRfc4122();
    }

    /**
     * The file as an answer, for whoever the caller found may see it:
     * images are shown, anything else, HTML or SVG included, is only
     * downloaded.
     */
    public function serve(Attachment $attachment): BinaryFileResponse
    {
        $image = $attachment->isImage();
        $response = new BinaryFileResponse($this->path($attachment), headers: [
            'Content-Type' => $image ? $attachment->getMediaType() : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition($image ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getFilename());
        $response->setAutoLastModified();
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }
}
