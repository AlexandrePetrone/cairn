<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use ApiPlatform\Metadata\McpTool;
use App\Api\Mcp\State\AddCommentProcessor;
use App\Api\Resource\CommentResource;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A comment written through the MCP server is private unless said
 * otherwise: what an assistant writes is for the team first.
 */
#[McpTool(
    name: 'add_comment',
    description: 'Writes a comment on an issue. It is private, read by internal users only, unless `private` is false: the client then reads it and is told by mail.',
    structuredContent: false,
    outputFormats: ['json' => ['application/json']],
    security: "is_granted('api.write')",
    validate: true,
    output: CommentResource::class,
    processor: AddCommentProcessor::class,
)]
final class AddCommentTool
{
    /** Key of the issue, `MD-SPT-12`. */
    #[Assert\NotBlank]
    public string $key = '';

    /** Markdown. */
    #[Assert\NotBlank(normalizer: 'trim')]
    public string $body = '';

    /** False to let the client read the comment. */
    public bool $private = true;
}
