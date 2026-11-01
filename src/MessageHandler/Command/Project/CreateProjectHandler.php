<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Project;

use App\Entity\Project;
use App\Message\Command\Project\CreateProject;
use App\Repository\ProjectRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateProjectHandler
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly ProjectRepository $projects,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * An archived workspace takes no new project: it would be born read-only.
     */
    public function __invoke(CreateProject $command): Project
    {
        $workspace = $this->workspaces->find($command->workspaceId) ?? throw ProjectException::notFound('Workspace', $command->workspaceId);
        if ($workspace->isArchived()) {
            throw ProjectException::archivedWorkspace($workspace->getName());
        }
        if (null !== $this->projects->findOneBy(['workspace' => $workspace, 'key' => $command->key])) {
            throw ProjectException::keyTaken($command->key, $workspace->getName());
        }

        $project = new Project($workspace, $command->name, $command->key);
        $this->entityManager->persist($project);

        return $project;
    }
}
