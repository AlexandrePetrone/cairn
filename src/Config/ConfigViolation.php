<?php

declare(strict_types=1);

namespace App\Config;

/**
 * One thing ConfigValidator finds wrong in a definition, located by a
 * dotted path such as `workflows.bug.transitions.refuse.to`.
 */
final readonly class ConfigViolation implements \Stringable
{
    public function __construct(
        public string $path,
        public string $message,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('%s: %s', $this->path, $this->message);
    }
}
