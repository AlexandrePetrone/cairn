<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Api\Resource\Value\FileRef;
use App\Api\Resource\Value\UserRef;
use App\Api\State\AddCommentProcessor;
use App\Api\State\CommentCollectionProvider;
use App\Api\State\CommentProvider;
use App\Api\State\DeleteCommentProcessor;
use App\Api\State\EditCommentProcessor;

/**
 * A comment of an issue. The private ones are for internal users.
 */
#[ApiResource(
    shortName: 'Comment',
    operations: [
        new GetCollection(
            uriTemplate: '/issues/{key}/comments',
            uriVariables: ['key' => new Link(fromClass: IssueResource::class)],
            description: 'The comments of the issue the user may read, oldest first.',
            paginationEnabled: false,
            provider: CommentCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/issues/{key}/comments',
            uriVariables: ['key' => new Link(fromClass: IssueResource::class)],
            input: CommentInput::class,
            read: false,
            processor: AddCommentProcessor::class,
        ),
        new Get(uriTemplate: '/comments/{id}', provider: CommentProvider::class),
        new Patch(uriTemplate: '/comments/{id}', input: CommentInput::class, provider: CommentProvider::class, processor: EditCommentProcessor::class),
        new Delete(uriTemplate: '/comments/{id}', provider: CommentProvider::class, processor: DeleteCommentProcessor::class),
    ],
)]
final readonly class CommentResource
{
    /**
     * @param list<FileRef> $attachments
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        /** Key of the issue. */
        public string $issue,
        /** Left out when an automation rule wrote the comment: `signature` then names the rule. */
        public ?UserRef $author,
        /** Markdown. */
        public string $body,
        public bool $private,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $editedAt,
        public array $attachments = [],
        public ?string $signature = null,
    ) {
    }
}
