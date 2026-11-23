<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use ApiPlatform\Metadata\McpTool;
use App\Api\Mcp\State\GetProjectProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A project and what its issues are made of.
 */
#[McpTool(
    name: 'get_project',
    description: 'Reads a project: its categories with the steps and the fields of their workflow (an archived one takes no new issue), its members, its releases and its tags. What creating, assigning or moving an issue needs to name.',
    structuredContent: false,
    outputFormats: ['json' => ['application/json']],
    security: "is_granted('api.read')",
    validate: true,
    output: ProjectView::class,
    processor: GetProjectProcessor::class,
)]
final class GetProjectTool
{
    /** Key of the project, `MD-SPT`. */
    #[Assert\NotBlank]
    public string $key = '';
}
