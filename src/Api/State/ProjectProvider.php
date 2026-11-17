<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Resource\ProjectResource;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\QueryBus;
use App\Security\Viewer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The projects of the viewer, or one of them: both read ListVisibleProjects,
 * so a project is found only where the list would show it.
 *
 * @implements ProviderInterface<ProjectResource>
 */
final class ProjectProvider implements ProviderInterface
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
    ) {
    }

    /**
     * @return ProjectResource|list<ProjectResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectResource|array
    {
        if ($operation instanceof CollectionOperationInterface) {
            $request = $context['request'] ?? null;

            return $this->projects($request instanceof Request ? $request->query->getString('q') : '');
        }

        return $this->one((string) $uriVariables['key']);
    }

    /**
     * The project named by its key (`MD-SPT`), with its counts: the list
     * narrowed to the projects bearing the key of the project, for the
     * MCP server too.
     */
    public function one(string $key): ProjectResource
    {
        $dash = strrpos($key, '-');
        foreach ($this->projects(false === $dash ? $key : substr($key, $dash + 1)) as $project) {
            if ($project->key === $key) {
                return $project;
            }
        }

        throw new NotFoundHttpException(\sprintf('No project %s.', $key));
    }

    /**
     * @return list<ProjectResource>
     */
    private function projects(string $q): array
    {
        return array_map(ProjectResource::of(...), $this->queries->ask(new ListVisibleProjects($this->viewer->get(), $q)));
    }
}
