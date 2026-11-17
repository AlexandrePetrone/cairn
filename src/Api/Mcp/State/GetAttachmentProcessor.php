<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\Refs;
use App\Api\Mcp\GetAttachmentTool;
use App\Api\State\Lookup;
use App\Attachment\AttachmentStorage;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The file itself, since its address needs a session or a token the
 * assistant's shell does not have: an image as an image (a screenshot is
 * seen; the client scales it down), a short text in UTF-8 as text, anything
 * else as its bytes, whatever its size or its encoding, for the client to
 * save and open. One file per answer: the transport carries 16 MiB, a file
 * weighs 10 MB at most. Who may see a file is decided by Lookup, as for the
 * REST API.
 *
 * @implements ProcessorInterface<GetAttachmentTool, CallToolResult>
 */
final class GetAttachmentProcessor implements ProcessorInterface
{
    /** A text longer than this is handed over as a file, not read into the conversation. */
    private const int MAX_TEXT = 200_000;

    public function __construct(
        private readonly Lookup $lookup,
        private readonly AttachmentStorage $storage,
        private readonly Refs $refs,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        \assert($data instanceof GetAttachmentTool);

        $attachment = $this->lookup->attachment($data->uuid);
        $path = $this->storage->path($attachment);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (false === $bytes) {
            throw new NotFoundHttpException(\sprintf('The file of attachment %s is missing.', $data->uuid));
        }

        $content = match (true) {
            $attachment->isImage() => new ImageContent(base64_encode($bytes), $attachment->getMediaType()),
            self::reads($attachment->getMediaType(), $bytes) => new TextContent($bytes),
            default => new EmbeddedResource(new BlobResourceContents($this->refs->file($attachment)->url, $attachment->getMediaType(), base64_encode($bytes))),
        };

        return CallToolResult::success([$content]);
    }

    /**
     * A text short enough for a conversation, and that JSON can carry as it is.
     */
    private static function reads(string $mediaType, string $bytes): bool
    {
        return (str_starts_with($mediaType, 'text/') || \in_array($mediaType, ['application/json', 'application/xml', 'application/x-ndjson'], true))
            && \strlen($bytes) <= self::MAX_TEXT
            && mb_check_encoding($bytes, 'UTF-8');
    }
}
