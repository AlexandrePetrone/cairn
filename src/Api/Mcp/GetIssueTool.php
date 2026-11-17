<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use ApiPlatform\Metadata\McpTool;
use App\Api\Mcp\State\GetIssueProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An issue as its page shows it, in one answer.
 */
#[McpTool(
    name: 'get_issue',
    description: 'Reads an issue whole: its properties, description, fields and files, the actions it offers, its comments with their files and the issues linked to it. A file is read with get_attachment.',
    structuredContent: false,
    outputFormats: ['json' => ['application/json']],
    security: "is_granted('api.read')",
    validate: true,
    output: IssueView::class,
    processor: GetIssueProcessor::class,
)]
final class GetIssueTool
{
    /** Key of the issue, `MD-SPT-12`. */
    #[Assert\NotBlank]
    public string $key = '';
}
