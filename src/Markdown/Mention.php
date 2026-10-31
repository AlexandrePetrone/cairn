<?php

declare(strict_types=1);

namespace App\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * `@[Name](user:12)`. As parsed, the name is the one written; the renderer
 * replaces the node with one carrying the user's current name.
 */
final class Mention extends AbstractInline
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
    ) {
        parent::__construct();
    }
}
