<?php

declare(strict_types=1);

namespace App\Config\Diff;

final readonly class DiffEntry implements \Stringable
{
    public function __construct(
        public DiffKind $kind,
        public string $path,
        public string $description,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('%s %s: %s', $this->kind->symbol(), $this->path, $this->description);
    }
}
