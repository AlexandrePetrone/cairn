<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Project;

use App\Message\Command\Project\DeleteProject;
use App\Repository\ProjectRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The command carries no actor: the administration is guarded at its
 * door. What goes, and in which order, is ProjectRemoval's.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteProjectHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectRemoval $removal,
    ) {
    }

    public function __invoke(DeleteProject $command): void
    {
        $project = $this->projects->find($command->projectId) ?? throw ProjectException::notFound('Project', $command->projectId);
        $this->removal->remove($project);
    }
}
