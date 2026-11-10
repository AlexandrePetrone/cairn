<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Api\Resource\Value\IssueRef;
use App\Api\State\AddLinkProcessor;
use App\Api\State\LinkCollectionProvider;
use App\Api\State\LinkProvider;
use App\Api\State\RemoveLinkProcessor;

/**
 * A link of an issue to another one, read from that issue: the same link
 * says "Blocks" on one side and "Blocked by" on the other.
 */
#[ApiResource(
    shortName: 'Link',
    operations: [
        new GetCollection(
            uriTemplate: '/issues/{key}/links',
            uriVariables: ['key' => new Link(fromClass: IssueResource::class)],
            description: 'The links of the issue whose other end the user may see, oldest first.',
            paginationEnabled: false,
            provider: LinkCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/issues/{key}/links',
            uriVariables: ['key' => new Link(fromClass: IssueResource::class)],
            input: LinkInput::class,
            read: false,
            processor: AddLinkProcessor::class,
        ),
        new Delete(uriTemplate: '/links/{id}', provider: LinkProvider::class, processor: RemoveLinkProcessor::class),
    ],
)]
final readonly class LinkResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        /** Key of the issue the link is read from. */
        public string $issue,
        /** Id of the link type. */
        public string $type,
        /** The type as this issue reads it: "Blocks", or "Blocked by" from the other side. */
        public string $label,
        /** Whether the issue is the target of the link. */
        public bool $inverse,
        /** The issue at the other end. */
        public IssueRef $other,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
