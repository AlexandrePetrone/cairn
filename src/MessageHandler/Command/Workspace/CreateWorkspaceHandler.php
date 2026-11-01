<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Workspace;

use App\Entity\Workspace;
use App\Message\Command\Workspace\CreateWorkspace;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateWorkspaceHandler
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateWorkspace $command): Workspace
    {
        if (null !== $this->workspaces->findOneBy(['key' => $command->key])) {
            throw WorkspaceException::keyTaken($command->key);
        }

        $workspace = new Workspace($command->name, $command->key);
        $this->entityManager->persist($workspace);

        return $workspace;
    }
}
