<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Api\Resource\Value\WorkspaceRef;
use App\Api\State\ProjectProvider;
use App\Message\Query\Project\ProjectSummary;

/**
 * A project the user may open, named by its key (`MD-SPT`: the workspace,
 * then the project), with the volume of its issues as the user sees it.
 */
#[ApiResource(
    shortName: 'Project',
    operations: [
        new GetCollection(
            uriTemplate: '/projects',
            description: 'The projects the user may open, by workspace.',
            paginationEnabled: false,
            provider: ProjectProvider::class,
            parameters: ['q' => new QueryParameter(schema: ['type' => 'string'], description: 'Part of the name or the key of a project, or of the name of its workspace.')],
        ),
        new Get(uriTemplate: '/projects/{key}', provider: ProjectProvider::class),
    ],
)]
final readonly class ProjectResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $key,
        public string $name,
        public ?string $description,
        public WorkspaceRef $workspace,
        /** Archived, or in an archived workspace: nothing changes there any more. */
        public bool $readOnly,
        /** Issues that are not finished. */
        public int $openIssues,
        /** Among them, those waiting for an answer. */
        public int $waitingIssues,
        /** Among them, the blocking ones. */
        public int $blockingIssues,
    ) {
    }

    public static function of(ProjectSummary $summary): self
    {
        $project = $summary->project;

        return new self(
            $project->getIssuePrefix(),
            $project->getName(),
            $project->getDescription(),
            WorkspaceRef::of($project->getWorkspace()),
            $project->isReadOnly(),
            $summary->open,
            $summary->waiting,
            $summary->blocking,
        );
    }
}
