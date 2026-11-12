<?php

declare(strict_types=1);

namespace App\Attachment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Where the pictures of the accounts live: `ATTACHMENTS_DIR/avatars`, one
 * file per picture, named by a uuid of its own and the extension of its
 * type. A new picture is a new name, so the address of a picture never
 * shows another one and browsers may keep it. Nothing else knows the
 * layout.
 */
final class AvatarStorage
{
    /** The images accepted, and the extension each is kept under. */
    public const array TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public const string NAME_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:png|jpg|webp)';

    public function __construct(
        #[Autowire('%env(resolve:ATTACHMENTS_DIR)%/avatars')]
        private readonly string $directory,
    ) {
    }

    /**
     * @return string the name the picture is kept under
     */
    public function store(UploadedFile $file): string
    {
        $extension = self::TYPES[(string) $file->getMimeType()] ?? throw new \InvalidArgumentException('A picture is a PNG, JPEG or WebP image.');
        $name = Uuid::v4()->toRfc4122().'.'.$extension;
        $file->move($this->directory, $name);

        return $name;
    }

    public function delete(string $name): void
    {
        $path = $this->path($name);
        if (null !== $path) {
            unlink($path);
        }
    }

    /**
     * The picture as an answer, or null when there is no such file.
     */
    public function serve(string $name): ?BinaryFileResponse
    {
        $path = $this->path($name);
        if (null === $path) {
            return null;
        }
        $response = new BinaryFileResponse($path, headers: [
            'Content-Type' => array_flip(self::TYPES)[pathinfo($name, \PATHINFO_EXTENSION)],
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        return $response;
    }

    private function path(string $name): ?string
    {
        if (1 !== preg_match('/^'.self::NAME_PATTERN.'$/D', $name)) {
            return null;
        }
        $path = $this->directory.'/'.$name;

        return is_file($path) ? $path : null;
    }
}
