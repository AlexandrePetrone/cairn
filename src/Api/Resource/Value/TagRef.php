<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

use App\Entity\Tag;

/**
 * A tag of the project an issue carries.
 */
final readonly class TagRef
{
    public function __construct(
        public int $id,
        public string $name,
        /** One of the palette: `red`, `blue`, `slate`… */
        public string $colour,
    ) {
    }

    public static function of(Tag $tag): self
    {
        return new self((int) $tag->getId(), $tag->getName(), $tag->getColour()->value);
    }
}
