<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use ApiPlatform\Metadata\McpTool;
use App\Api\Mcp\State\MoveIssueProcessor;
use App\Api\Resource\IssueResource;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What MoveIssue takes, without its actor.
 */
#[McpTool(
    name: 'move_issue',
    description: 'Takes an issue to a step of its workflow: by the action of the user that leads there when there is one, otherwise outside the workflow, for whoever may.',
    structuredContent: false,
    outputFormats: ['json' => ['application/json']],
    security: "is_granted('api.write')",
    validate: true,
    output: IssueResource::class,
    processor: MoveIssueProcessor::class,
)]
final class MoveIssueTool
{
    /** Key of the issue, `MD-SPT-12`. */
    #[Assert\NotBlank]
    public string $key = '';

    /** Id of a step of the workflow of the issue, `in_progress` (get_project lists them). */
    #[Assert\NotBlank(normalizer: 'trim')]
    public string $place = '';
}
