<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * A file of an issue or of a comment.
 */
final readonly class FileRef
{
    public function __construct(
        public string $uuid,
        public string $name,
        public string $mediaType,
        public int $size,
        /** Where a program downloads it, with its token. */
        public string $contentUrl,
        /** Where a person signed in to the application opens it. */
        public string $url,
    ) {
    }
}
