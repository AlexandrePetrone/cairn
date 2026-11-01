<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Project;

use App\Message\Command\Project\UpdateProject;
use App\Repository\ProjectRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateProjectHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
    ) {
    }

    public function __invoke(UpdateProject $command): void
    {
        $project = $this->projects->find($command->projectId) ?? throw ProjectException::notFound('Project', $command->projectId);

        if (null !== $command->name) {
            $project->rename($command->name);
        }
        if (null !== $command->archived) {
            $command->archived ? $project->archive() : $project->unarchive();
        }
    }
}
