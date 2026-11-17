<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use ApiPlatform\Metadata\McpTool;
use App\Api\Mcp\State\GetAttachmentProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The content of a file, for a reader that sees images.
 */
#[McpTool(
    name: 'get_attachment',
    description: 'Reads a file of an issue or of a comment, named by its uuid: an image (a screenshot) comes as an image, a short text as its text, anything else (a PDF, an archive, a long or oddly encoded text) as the file itself.',
    structuredContent: false,
    outputFormats: ['json' => ['application/json']],
    security: "is_granted('api.read')",
    validate: true,
    processor: GetAttachmentProcessor::class,
)]
final class GetAttachmentTool
{
    /** Uuid of the file, as get_issue gives it. */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $uuid = '';
}
