<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Api\State\TagCollectionProvider;
use App\Entity\Tag;

/**
 * A tag of a project, which its issues carry, several per issue.
 */
#[ApiResource(
    shortName: 'Tag',
    operations: [
        new GetCollection(
            uriTemplate: '/projects/{key}/tags',
            uriVariables: ['key' => new Link(fromClass: ProjectResource::class)],
            description: 'The tags of the project, by name.',
            paginationEnabled: false,
            provider: TagCollectionProvider::class,
        ),
    ],
)]
final readonly class TagResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
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
