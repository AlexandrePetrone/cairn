<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use ApiPlatform\Metadata\McpTool;
use App\Api\Mcp\State\ChangeTrackerProcessor;
use App\Api\Resource\IssueResource;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What ChangeTracker takes, without its actor. A tool of its own, apart
 * from move_issue: the issue changes workflow, which an assistant must
 * not do for an argument too many.
 */
#[McpTool(
    name: 'change_tracker',
    description: 'Moves an issue to another category of its project, on a step of the workflow of that category. Only when asked to: the issue changes workflow. Within its category, use move_issue.',
    structuredContent: false,
    outputFormats: ['json' => ['application/json']],
    security: "is_granted('api.write')",
    validate: true,
    output: IssueResource::class,
    processor: ChangeTrackerProcessor::class,
)]
final class ChangeTrackerTool
{
    /** Key of the issue, `MD-SPT-12`. */
    #[Assert\NotBlank]
    public string $key = '';

    /** Id of another category of the project (get_project lists them). */
    #[Assert\Positive]
    public int $tracker = 0;

    /** Id of a step of the workflow of that category, where the issue starts in it (get_project lists them). */
    #[Assert\NotBlank(normalizer: 'trim')]
    public string $place = '';
}
