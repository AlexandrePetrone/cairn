<?php

declare(strict_types=1);

namespace App\Forge\Event;

/**
 * One commit of a push, as every forge describes it.
 */
final readonly class CommitPayload
{
    public function __construct(
        public string $sha,
        public string $title,
        public string $message,
        public string $url,
        public string $authorName,
    ) {
    }
}
