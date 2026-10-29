<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a `#[CurrentProject]` argument from the `workspaceKey` and
 * `projectKey` parameters of the route: the one place that knows how a
 * project is addressed in a URL.
 */
#[AsTargetedValueResolver]
final class CurrentProjectResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly ProjectRepository $projects,
    ) {
    }

    /**
     * @return list<Project>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $workspaceKey = $request->attributes->getString('workspaceKey');
        $projectKey = $request->attributes->getString('projectKey');
        if ('' === $workspaceKey || '' === $projectKey) {
            throw new \LogicException(\sprintf('#[CurrentProject] needs the "workspaceKey" and "projectKey" route parameters, missing on route "%s".', $request->attributes->getString('_route')));
        }

        return [$this->projects->findOneByKeys($workspaceKey, $projectKey) ?? throw new NotFoundHttpException(\sprintf('No project %s-%s.', $workspaceKey, $projectKey))];
    }
}
