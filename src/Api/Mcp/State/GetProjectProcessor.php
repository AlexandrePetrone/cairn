<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\Refs;
use App\Api\Mapper\TrackerMapper;
use App\Api\Mcp\GetProjectTool;
use App\Api\Mcp\ProjectView;
use App\Api\Resource\MemberResource;
use App\Api\Resource\ProjectResource;
use App\Api\Resource\ReleaseResource;
use App\Api\State\Lookup;
use App\Entity\User;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListTrackers;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\Query\Release\ListReleases;
use App\Message\QueryBus;
use App\Security\Viewer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The project is found once, then asked for what the REST API gives
 * under it, through the same queries.
 *
 * @implements ProcessorInterface<GetProjectTool, ProjectView>
 */
final class GetProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly TrackerMapper $trackers,
        private readonly Refs $refs,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectView
    {
        \assert($data instanceof GetProjectTool);
        $project = $this->lookup->project($data->key);
        $viewer = $this->viewer->get();

        return new ProjectView(
            $this->summary($viewer, $data->key, $project->getKey()),
            array_map($this->trackers->map(...), $this->queries->ask(new ListTrackers($viewer, $project))),
            array_map(fn (User $user): MemberResource => MemberResource::of($this->refs->user($user)), $this->queries->ask(new ListProjectMembers($project))),
            array_map(ReleaseResource::of(...), $this->queries->ask(new ListReleases($viewer, $project))),
        );
    }

    /**
     * The project with its counts, as the list of the projects gives it:
     * the list narrowed to the projects bearing that key.
     */
    private function summary(User $viewer, string $key, string $projectKey): ProjectResource
    {
        foreach ($this->queries->ask(new ListVisibleProjects($viewer, $projectKey)) as $summary) {
            $resource = ProjectResource::of($summary);
            if ($resource->key === $key) {
                return $resource;
            }
        }

        throw new NotFoundHttpException(\sprintf('No project %s.', $key));
    }
}
