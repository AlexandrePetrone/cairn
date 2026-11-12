<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Gives an account the picture its owner chose, a PNG, JPEG or WebP image
 * up to 2 MB (the page shrinks it to a small square before sending it),
 * or takes it away when no file comes: the initials show again. Handled
 * by ChangeAvatarHandler.
 */
final readonly class ChangeAvatar
{
    public function __construct(
        public int $userId,
        #[Assert\Image(maxSize: '2M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp'])]
        public ?UploadedFile $file = null,
    ) {
    }
}
