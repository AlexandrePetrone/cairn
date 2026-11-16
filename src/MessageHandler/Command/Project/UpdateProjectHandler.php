<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Project;

use App\Message\Command\Project\UpdateProject;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ProjectRepository;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateProjectHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(UpdateProject $command): void
    {
        $project = $this->projects->find($command->projectId) ?? throw ProjectException::notFound('Project', $command->projectId);

        $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectConfigure, $project);
        if (null !== $command->actorId && null !== $command->archived) {
            throw new AccessDeniedException('Only the administration archives a project.');
        }

        if (null !== $command->name) {
            $project->rename($command->name);
        }
        if (null !== $command->description) {
            $project->setDescription($command->description);
        }
        if (null !== $command->archived) {
            $command->archived ? $project->archive() : $project->unarchive();
        }
        if ($command->withoutBudgets) {
            $project->countBudgetsIn(null);
        } elseif (null !== $command->budgetUnit) {
            $project->countBudgetsIn($command->budgetUnit);
        }
    }
}
