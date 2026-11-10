<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Api\Resource\Value\FileRef;
use App\Api\State\AttachmentContentProvider;
use App\Api\State\AttachmentProvider;
use App\Api\State\UploadAttachmentProcessor;

/**
 * A file of an issue or of a comment, read by whoever sees what it goes
 * with. Sent ahead of it, it waits, seen by its uploader
 * only, until its `uuid` is named in the `attachments` of a new issue, a
 * comment or an action. A file nobody claimed is deleted after a day.
 */
#[ApiResource(
    shortName: 'Attachment',
    operations: [
        new Get(uriTemplate: '/attachments/{uuid}', provider: AttachmentProvider::class),
        new Get(
            uriTemplate: '/attachments/{uuid}/content',
            description: 'The file itself, for whoever may see what it goes with.',
            openapi: new Operation(responses: ['200' => new OpenApiResponse(description: 'The content of the file.', content: new \ArrayObject(['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]))]),
            provider: AttachmentContentProvider::class,
            name: self::CONTENT_ROUTE,
        ),
        new Post(
            uriTemplate: '/projects/{key}/attachments',
            uriVariables: ['key' => new Link(fromClass: ProjectResource::class)],
            inputFormats: ['multipart' => ['multipart/form-data']],
            description: 'Uploads a file of 10 MB at most, in the `file` part of a multipart form.',
            openapi: new Operation(requestBody: new RequestBody(content: new \ArrayObject([
                'multipart/form-data' => ['schema' => ['type' => 'object', 'required' => ['file'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]]],
            ]))),
            read: false,
            deserialize: false,
            validate: false,
            processor: UploadAttachmentProcessor::class,
        ),
    ],
)]
final readonly class AttachmentResource
{
    public const string CONTENT_ROUTE = 'api_attachment_content';

    public function __construct(
        #[ApiProperty(identifier: true)]
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

    public static function of(FileRef $file): self
    {
        return new self($file->uuid, $file->name, $file->mediaType, $file->size, $file->contentUrl, $file->url);
    }
}
